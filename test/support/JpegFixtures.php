<?php

declare(strict_types=1);

namespace Php2PdfTest\Support;

use function count;
use function pack;
use function strlen;
use function substr;

/**
 * JPEGs with EXIF blocks: an APP1 "Exif" segment holding a TIFF header and one image directory
 * with orientation and, optionally, resolution tags, inserted after the start-of-image marker.
 */
final class JpegFixtures
{
    /**
     * @param array{0: int, 1: int, 2: int}|null $resolution x and y in whole units, and the unit
     *                                                       (2 inches, 3 centimetres)
     * @param 'II'|'MM'                          $byteOrder  Intel (little-endian) or Motorola
     */
    public static function withExif(
        string $jpeg,
        int $orientation,
        ?array $resolution = null,
        string $byteOrder = 'II',
    ): string {
        $u16     = static fn(int $v): string => pack('II' === $byteOrder ? 'v' : 'n', $v);
        $u32     = static fn(int $v): string => pack('II' === $byteOrder ? 'V' : 'N', $v);
        $entries = [[0x0112, 3, $u16($orientation) . "\0\0"]];
        $extra   = '';
        $count   = null === $resolution ? 1 : 4;
        $dataAt  = 8 + 2 + ($count * 12) + 4;
        if (null !== $resolution) {
            $entries[] = [0x011A, 5, $u32($dataAt)];
            $entries[] = [0x011B, 5, $u32($dataAt + 8)];
            $entries[] = [0x0128, 3, $u16($resolution[2]) . "\0\0"];
            $extra     = $u32($resolution[0]) . $u32(1) . $u32($resolution[1]) . $u32(1);
        }
        $tiff = ('II' === $byteOrder ? 'II' : 'MM') . $u16(42) . $u32(8) . $u16(count($entries));
        foreach ($entries as [$tag, $type, $value]) {
            $tiff .= $u16($tag) . $u16($type) . $u32(1) . $value;
        }
        $tiff    .= $u32(0) . $extra;
        $segment = "Exif\0\0{$tiff}";

        return (
            substr($jpeg, offset: 0, length: 2)
                . "\xFF\xE1"
                . pack('n', strlen($segment) + 2)
                . $segment
                . substr($jpeg, offset: 2)
        );
    }
}
