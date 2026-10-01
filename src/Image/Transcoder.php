<?php

declare(strict_types=1);

namespace PhpPdf\Image;

use GdImage;
use Imagick;
use PhpPdf\Core\Silencer;
use PhpPdf\Exception\PdfException;

use function extension_loaded;
use function imagecreatefromstring;
use function imagepng;
use function imagesavealpha;
use function is_string;
use function ob_get_clean;
use function ob_start;

/**
 * Converts image formats the library does not read natively (GIF, WebP, BMP, TIFF, ...) to PNG
 * through ext-imagick when loaded, else ext-gd. Neither extension is required for JPEG and PNG.
 *
 * @internal
 */
final class Transcoder
{
    public static function isAvailable(): bool
    {
        return extension_loaded('imagick') || extension_loaded('gd');
    }

    public static function toPng(string $data): string
    {
        if (extension_loaded('imagick')) {
            return self::viaImagick($data);
        }
        if (extension_loaded('gd')) {
            return self::viaGd($data);
        }
        throw new PdfException('Only JPEG and PNG are read natively; install ext-imagick or ext-gd for other formats.');
    }

    private static function viaGd(string $data): string
    {
        $image = Silencer::run(static fn(): GdImage|false => imagecreatefromstring($data));
        if (false === $image) {
            throw new PdfException('ext-gd could not read the image.');
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

    private static function viaImagick(string $data): string
    {
        $image = new Imagick();
        $image->readImageBlob($data);
        $image->setImageFormat('png');

        return $image->getImageBlob();
    }
}
