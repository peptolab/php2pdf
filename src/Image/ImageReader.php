<?php

declare(strict_types=1);

namespace PhpPdf\Image;

use PhpPdf\Exception\IoException;

use function file_get_contents;
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
    public static function read(string $data): ImageData
    {
        if (str_starts_with($data, "\xFF\xD8")) {
            return JpegDecoder::decode($data);
        }
        if (str_starts_with($data, "\x89PNG")) {
            return PngDecoder::decode($data);
        }

        return PngDecoder::decode(Transcoder::toPng($data));
    }

    public static function readFile(string $path): ImageData
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new IoException(sprintf('Image file "%s" not found.', $path));
        }
        $data = file_get_contents($path);
        if (false === $data) {
            throw new IoException(sprintf('Cannot read image file "%s".', $path));
        }

        return self::read($data);
    }
}
