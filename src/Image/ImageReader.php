<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Php2Pdf\Core\Limits;
use Php2Pdf\Core\Silencer;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PdfException;

use function array_filter;
use function file_get_contents;
use function getimagesizefromstring;
use function is_file;
use function is_readable;
use function sprintf;
use function str_starts_with;

/**
 * Picks the decoder for an image by sniffing its bytes.
 *
 * @api
 */
final class ImageReader
{
    /**
     * @param ImageOptions|null $options the page of a multi-page file (TIFF, GIF), and whether a
     *                                   conversion that loses data is accepted
     * @param string|null       $name    the file the data came from, for error messages
     *
     * @throws PdfException for unreadable data, a missing page, an image over the limits, or a
     *                      conversion that would lose data when that is not accepted
     */
    public static function read(
        string $data,
        ?Limits $limits = null,
        ?ImageOptions $options = null,
        ?string $name = null,
    ): ImageData {
        $limits  ??= Limits::default();
        $options ??= new ImageOptions();
        $single  = ["\xFF\xD8", "\x89PNG", JpxDecoder::JP2_SIGNATURE, JpxDecoder::CODESTREAM];
        if (
            $options->page > 1
            && [] !== array_filter($single, static fn(string $magic): bool => str_starts_with($data, $magic))
        ) {
            throw new ParseException(sprintf(
                'JPEG, JPEG 2000 and PNG files have one page; page %d was asked for.',
                $options->page,
            ));
        }
        $image = self::decode($data, $limits, $options, $name);
        $limits->assertImagePixels($image->width, $image->height);

        return $image;
    }

    public static function readFile(string $path, ?Limits $limits = null, ?ImageOptions $options = null): ImageData
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new IoException(sprintf('Image file "%s" not found.', $path));
        }
        $data = file_get_contents($path);
        if (false === $data) {
            throw new IoException(sprintf('Cannot read image file "%s".', $path));
        }

        return self::read($data, $limits, $options, $path);
    }

    /**
     * Each decoder checks the pixel limit as soon as it has read the image's header, before any
     * pixels are decoded; read() checks the result again, so no format gets past the limit. A
     * format or TIFF layout not read natively is converted to PNG, which is refused when it would
     * lose data, unless the options accept that.
     */
    private static function decode(string $data, Limits $limits, ImageOptions $options, ?string $name): ImageData
    {
        if (str_starts_with($data, "\xFF\xD8")) {
            return JpegDecoder::decode($data, $limits);
        }
        if (str_starts_with($data, JpxDecoder::JP2_SIGNATURE) || str_starts_with($data, JpxDecoder::CODESTREAM)) {
            return JpxDecoder::decode($data, $limits);
        }
        $traits = null;
        if (TiffFile::isTiff($data)) {
            $tiff = TiffDecoder::decode($data, $limits, $options->page);
            if (null !== $tiff) {
                return $tiff;
            }
            $traits = TiffDecoder::traits($data, $options->page);
        }
        if (str_starts_with($data, "\x89PNG")) {
            return PngDecoder::decode($data, $limits);
        }
        $size = Silencer::run(
            /** @return array{0: int, 1: int, 2: int, 3: string, bits: int, channels: int, mime: string}|false */
            static fn(): array|false => getimagesizefromstring($data),
        );
        if (false !== $size) {
            $limits->assertImagePixels($size[0], $size[1]);
        }
        $png = Transcoder::toPng(
            $data,
            $options->page,
            $traits ?? ImageTraits::sniff($data, $size),
            match (true) {
                $options->allowLossyConversion => null,
                null === $name => 'The image',
                default => sprintf('Image file "%s"', $name),
            },
        );

        return PngDecoder::decode($png, $limits);
    }
}
