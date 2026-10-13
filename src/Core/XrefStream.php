<?php

declare(strict_types=1);

namespace Php2Pdf\Core;

use function chr;
use function max;
use function ord;
use function str_repeat;
use function strlen;

/**
 * A cross-reference stream's entries as its data (PDF 1.5): each entry its type, its offset or
 * object stream, and its generation or index, in fields as wide as the largest needs, and each row
 * predicted from the one above (PNG "Up", predictor 12) so the columns that barely change from
 * one entry to the next compress to almost nothing.
 *
 * @internal
 */
final class XrefStream
{
    /**
     * The widths of the three fields, and the rows predicted, for a /W of the widths and
     * /DecodeParms of predictor 12 with as many columns as the widths add up to.
     *
     * @param list<array{0: int, 1: int, 2: int}> $entries by object number, from 0
     *
     * @return array{0: array{0: int, 1: int, 2: int}, 1: string}
     */
    public static function encode(array $entries): array
    {
        $largestOffset = 0;
        $largestIndex  = 0;
        foreach ($entries as [, $offset, $index]) {
            $largestOffset = max($largestOffset, $offset);
            $largestIndex  = max($largestIndex, $index);
        }
        $second = self::bytes($largestOffset);
        $third  = self::bytes($largestIndex);
        $widths = [1, $second, $third];
        $data   = '';
        $above  = str_repeat("\0", 1 + $second + $third);
        foreach ($entries as [$type, $offset, $index]) {
            $row   = self::field($type, 1) . self::field($offset, $second) . self::field($index, $third);
            $data  .= "\x02" . self::up($row, $above);
            $above = $row;
        }

        return [$widths, $data];
    }

    /**
     * How many bytes an unsigned number takes, at least one.
     *
     * @return positive-int
     */
    private static function bytes(int $n): int
    {
        $bytes = 1;
        while ($n >= (1 << (8 * $bytes))) {
            ++$bytes;
        }

        return $bytes;
    }

    /** A number in $width bytes, big-endian. */
    private static function field(int $n, int $width): string
    {
        $bytes = '';
        for ($i = $width - 1; $i >= 0; --$i) {
            $bytes .= chr(($n >> (8 * $i)) & 0xFF);
        }

        return $bytes;
    }

    /** Each byte of the row less the byte above it, modulo 256. */
    private static function up(string $row, string $above): string
    {
        $out = '';
        for ($i = 0, $n = strlen($row); $i < $n; ++$i) {
            $out .= chr((ord($row[$i]) - ord($above[$i])) & 0xFF);
        }

        return $out;
    }
}
