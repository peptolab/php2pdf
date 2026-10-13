<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset;

use function chr;
use function max;
use function ord;
use function str_repeat;
use function strlen;
use function substr;

/** A cross-reference stream's rows read back: PNG "Up" prediction undone, and each row's fields. */
final class XrefRows
{
    /**
     * @param array{0: int, 1: int, 2: int} $widths
     *
     * @return list<array{0: int, 1: int, 2: int}>
     */
    public static function decode(string $data, array $widths): array
    {
        $columns = max(0, $widths[0] + $widths[1] + $widths[2]);
        $above   = str_repeat("\0", $columns);
        $rows    = [];
        for ($at = 0, $n = strlen($data); $at < $n; $at += $columns + 1) {
            $row = '';
            for ($i = 0; $i < $columns; ++$i) {
                $row .= chr((ord($data[$at + 1 + $i] ?? "\0") + ord($above[$i] ?? "\0")) & 0xFF);
            }
            $rows[] = [
                self::number(substr($row, offset: 0, length: $widths[0])),
                self::number(substr($row, $widths[0], $widths[1])),
                self::number(substr($row, $widths[0] + $widths[1], $widths[2])),
            ];
            $above = $row;
        }

        return $rows;
    }

    private static function number(string $bytes): int
    {
        $n = 0;
        for ($i = 0, $len = strlen($bytes); $i < $len; ++$i) {
            $n = ($n << 8) | ord($bytes[$i]);
        }

        return $n;
    }
}
