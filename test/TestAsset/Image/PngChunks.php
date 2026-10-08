<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset\Image;

use function crc32;
use function gzcompress;
use function implode;
use function pack;
use function strlen;

/**
 * PNG chunks built in memory, and a file made of them after the PNG signature and ending with
 * IEND, so a test can give a file any header, metadata chunk or malformed chunk.
 */
final class PngChunks
{
    public static function chunk(string $type, string $data): string
    {
        return pack('N', strlen($data)) . $type . $data . pack('N', crc32($type . $data));
    }

    public static function file(string ...$chunks): string
    {
        return "\x89PNG\r\n\x1A\n" . implode('', $chunks) . self::chunk('IEND', '');
    }

    public static function header(int $width, int $height, int $depth = 8, int $type = 0): string
    {
        return self::chunk('IHDR', pack('NNCCCCC', $width, $height, $depth, $type, 0, 0, 0));
    }

    /** An IDAT chunk holding the scanlines, unfiltered rows each led by a filter type byte, zlib compressed. */
    public static function imageData(string $scanlines): string
    {
        return self::chunk('IDAT', (string) gzcompress($scanlines));
    }
}
