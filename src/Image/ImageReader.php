<?php

declare(strict_types=1);

namespace PhpPdf\Image;

use PhpPdf\Core\Limits;
use PhpPdf\Core\Silencer;
use PhpPdf\Exception\IoException;
use PhpPdf\Exception\PdfException;

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
     * @param int $page the 1-based page of a multi-page file (TIFF, GIF), read through ext-imagick
     *
     * @throws PdfException for unreadable data, a missing page or an image over the limits
     */
    public static function read(string $data, ?Limits $limits = null, int $page = 1): ImageData
    {
        $limits ??= Limits::default();
        if ($page > 1 && (str_starts_with($data, "\xFF\xD8") || str_starts_with($data, "\x89PNG"))) {
            throw new PdfException(sprintf('JPEG and PNG files have one page; page %d was asked for.', $page));
        }
        if (str_starts_with($data, "\xFF\xD8")) {
            return JpegDecoder::decode($data);
        }
        if (str_starts_with($data, "\x89PNG")) {
            return PngDecoder::decode($data, $limits);
        }
        $size = Silencer::run(
            /** @return array{0: int, 1: int, 2: int, 3: string, bits: int, channels: int, mime: string}|false */
            static fn(): array|false => getimagesizefromstring($data),
        );
        if (false !== $size && ($size[0] * $size[1]) > $limits->maxImagePixels) {
            throw new PdfException(sprintf(
                'The image is %d by %d pixels, more than the %d allowed.',
                $size[0],
                $size[1],
                $limits->maxImagePixels,
            ));
        }

        return PngDecoder::decode(Transcoder::toPng($data, $page), $limits);
    }

    public static function readFile(string $path, ?Limits $limits = null): ImageData
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new IoException(sprintf('Image file "%s" not found.', $path));
        }
        $data = file_get_contents($path);
        if (false === $data) {
            throw new IoException(sprintf('Cannot read image file "%s".', $path));
        }

        return self::read($data, $limits);
    }
}
