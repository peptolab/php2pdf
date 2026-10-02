<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use GdImage;
use Imagick;
use ImagickException;
use Php2Pdf\Core\Silencer;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PdfException;

use function extension_loaded;
use function imagecreatefromstring;
use function imagepng;
use function imagesavealpha;
use function implode;
use function is_string;
use function ob_get_clean;
use function ob_start;
use function ord;
use function sprintf;
use function str_contains;
use function strlen;
use function strpos;
use function substr;

/**
 * Converts image formats the library does not read natively (GIF, WebP, BMP, TIFF layouts the
 * decoder does not handle, ...) to PNG through ext-imagick when loaded, else ext-gd. Neither
 * extension is required for JPEG and PNG.
 *
 * A conversion that would lose data is refused unless the caller accepts it: a colour model PNG
 * cannot hold (CMYK, CIELab), an ICC profile, samples deeper than the PNG, or an alpha channel
 * the PNG does not keep. What is known of the source is checked before converting, and the PNG
 * produced is checked against it afterwards.
 *
 * @internal
 */
final class Transcoder
{
    public static function isAvailable(): bool
    {
        return extension_loaded('imagick') || extension_loaded('gd');
    }

    /**
     * @param int         $page    the 1-based page of a multi-page file; pages after the first
     *                             need ext-imagick
     * @param ImageTraits $source  what is already known of the source, from its own header
     * @param string|null $subject the image as a refusal names it, or null to accept a conversion
     *                             that loses data
     *
     * @throws PdfException when the image cannot be converted, or would lose data
     */
    public static function toPng(
        string $data,
        int $page = 1,
        ImageTraits $source = new ImageTraits(),
        ?string $subject = 'The image',
    ): string {
        self::refuseLosses($source, self::bestPng(), $subject);
        [$png, $source] = self::convert($data, $page, $source, $subject);
        self::refuseLosses($source, self::pngTraits($png), $subject);

        return $png;
    }

    /** The most a PNG can keep of an image: 16-bit samples and alpha, but not CMYK. */
    private static function bestPng(): ImageTraits
    {
        return new ImageTraits(
            bits: 16,
            alpha: true,
        );
    }

    /** @return array{0: string, 1: ImageTraits} the PNG, and what is known of the source */
    private static function convert(string $data, int $page, ImageTraits $source, ?string $subject): array
    {
        if (extension_loaded('imagick')) {
            return self::viaImagick($data, $page, $source, $subject);
        }
        if ($page > 1) {
            throw new PdfException(sprintf('Reading page %d of a multi-page image needs ext-imagick.', $page));
        }
        if (! extension_loaded('gd')) {
            throw new PdfException(
                'Only JPEG and PNG are read natively; install ext-imagick or ext-gd for other formats.',
            );
        }

        return [self::viaGd($data), $source];
    }

    /** The PNG's bits per sample (8 for a palette) and whether it keeps transparency. */
    private static function pngTraits(string $png): ImageTraits
    {
        if (strlen($png) < 26) {
            throw new PdfException('The image was not converted to PNG.');
        }
        $depth = ord($png[24]);
        $type  = ord($png[25]);
        $idat  = strpos($png, needle: 'IDAT');
        $head  = false === $idat ? $png : substr($png, offset: 0, length: $idat);

        return new ImageTraits(
            bits: 3 === $type ? 8 : $depth,
            alpha: 4 === $type || 6 === $type || str_contains($head, 'tRNS'),
        );
    }

    /**
     * Refuses a conversion from $source to $result that loses data, unless there is no $subject
     * because losses are accepted.
     */
    private static function refuseLosses(ImageTraits $source, ImageTraits $result, ?string $subject): void
    {
        $losses = $source->lostIn($result);
        if (null === $subject || [] === $losses) {
            return;
        }
        throw new ParseException(sprintf(
            '%s is not read natively, and converting it to PNG would lose data: %s. Load it with '
                . 'new ImageOptions(allowLossyConversion: true) to accept the conversion, or convert it '
                . 'yourself.',
            $subject,
            implode('; ', $losses),
        ));
    }

    private static function viaGd(string $data): string
    {
        $image = Silencer::run(static fn(): GdImage|false => imagecreatefromstring($data));
        if (false === $image) {
            throw new ParseException('ext-gd could not read the image.');
        }
        imagesavealpha($image, enable: true);
        ob_start();
        $ok  = imagepng($image, quality: 6);
        $png = ob_get_clean();
        if (! $ok || ! is_string($png) || '' === $png) {
            throw new PdfException('ext-gd failed to transcode the image.');
        }

        return $png;
    }

    /**
     * Converts through ext-imagick, asking for 16-bit samples when the source is deeper than 8
     * bits and an alpha channel when it has one, so the PNG keeps both.
     *
     * @param string|null $subject the image as error messages name it, or null when losses are
     *                             accepted
     *
     * @return array{0: string, 1: ImageTraits} the PNG, and what is known of the source
     */
    private static function viaImagick(string $data, int $page, ImageTraits $known, ?string $subject): array
    {
        $image = new Imagick();
        try {
            $image->readImageBlob($data);
        } catch (ImagickException $e) {
            throw new ParseException(sprintf('ext-imagick could not read the image: %s', $e->getMessage()), 0, $e);
        }
        if ($page > $image->getNumberImages()) {
            throw new ParseException(sprintf(
                'The image has %d pages; page %d was asked for.',
                $image->getNumberImages(),
                $page,
            ));
        }
        $image->setIteratorIndex($page - 1);
        $colourspace = $image->getImageColorspace();
        $source      = $known->merge(new ImageTraits(
            Imagick::COLORSPACE_CMYK === $colourspace ? 'CMYK' : null,
            [] !== $image->getImageProfiles('icc', include_values: false)
                || [] !== $image->getImageProfiles('icm', include_values: false),
            $image->getImageDepth(),
            $image->getImageAlphaChannel(),
        ));
        self::refuseLosses($source, self::bestPng(), $subject);
        if (null !== $source->colourModel) {
            $image->profileImage('icc', null);
            $image->profileImage('icm', null);
            $image->transformImageColorspace(Imagick::COLORSPACE_SRGB);
        }
        if ($source->bits > 8) {
            $image->setOption('png:bit-depth', '16');
        }
        if ($source->alpha) {
            $image->setOption('png:color-type', Imagick::COLORSPACE_GRAY === $colourspace ? '4' : '6');
        }
        $image->setImageFormat('png');

        return [$image->getImageBlob(), $source];
    }
}
