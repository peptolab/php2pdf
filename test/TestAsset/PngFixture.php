<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset;

use function crc32;
use function intdiv;
use function max;
use function pack;
use function str_split;
use function strlen;

/**
 * A PNG built in memory from compressed image data (filtered scanlines, as zlib or, as a damaged
 * file might have them, raw deflate), split over several IDAT chunks.
 */
final class PngFixture
{
    public static function bytes(
        int $width,
        int $height,
        int $depth,
        int $type,
        string $data,
        int $chunks = 1,
    ): string {
        $png   = "\x89PNG\r\n\x1A\n" . self::chunk('IHDR', pack('NNCCCCC', $width, $height, $depth, $type, 0, 0, 0));
        $piece = max(1, intdiv(strlen($data) + $chunks - 1, $chunks));
        foreach (str_split($data, $piece) as $part) {
            $png .= self::chunk('IDAT', $part);
        }

        return $png . self::chunk('IEND', '');
    }

    private static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }
}
