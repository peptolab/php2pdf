<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset\Image;

use function chr;
use function implode;
use function pack;
use function str_repeat;
use function strlen;

/**
 * JPEG marker segments built in memory, and a file made of them: the start-of-image marker, the
 * segments, then a start-of-scan marker where the decoder stops reading.
 */
final class JpegSegments
{
    public static function file(string ...$segments): string
    {
        return "\xFF\xD8" . implode('', $segments) . "\xFF\xDA\x00\x02\xFF\xD9";
    }

    /**
     * A baseline 8-bit frame header.
     *
     * @param non-negative-int $components
     */
    public static function frame(int $width, int $height, int $components = 1): string
    {
        return self::segment(
            0xC0,
            pack('CnnC', 8, $height, $width, $components) . str_repeat("\x01\x11\x00", $components),
        );
    }

    public static function segment(int $marker, string $payload): string
    {
        return "\xFF" . chr($marker) . pack('n', strlen($payload) + 2) . $payload;
    }
}
