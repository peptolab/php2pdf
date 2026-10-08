<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset\Image;

use Php2Pdf\Image\JpxDecoder;

use function implode;
use function pack;
use function strlen;

/**
 * JP2 boxes built in memory, and a file made of them after the JP2 signature box. Only the header
 * boxes the decoder reads are built; no codestream follows.
 */
final class Jp2Boxes
{
    public static function box(string $type, string $payload): string
    {
        return pack('N', 8 + strlen($payload)) . $type . $payload;
    }

    /** A box whose length is given in the 64-bit extended length field. */
    public static function extendedBox(string $type, string $payload): string
    {
        return pack('N', 1) . $type . pack('J', 16 + strlen($payload)) . $payload;
    }

    public static function file(string ...$boxes): string
    {
        return JpxDecoder::JP2_SIGNATURE . implode('', $boxes);
    }

    /** The jp2h header box: an 8-bit image header followed by any further boxes. */
    public static function header(int $width, int $height, int $components, string ...$boxes): string
    {
        return self::box('jp2h', self::imageHeader($width, $height, $components) . implode('', $boxes));
    }

    public static function imageHeader(int $width, int $height, int $components): string
    {
        return self::box('ihdr', pack('NNnCCCC', $height, $width, $components, 7, 7, 0, 0));
    }
}
