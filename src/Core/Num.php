<?php

declare(strict_types=1);

namespace PhpPdf\Core;

use function abs;
use function floor;
use function implode;
use function is_int;
use function number_format;
use function rtrim;

/**
 * Number formatting for PDF syntax: no exponents, no trailing zeros, no negative zero.
 *
 * @internal
 */
final class Num
{
    public static function format(float|int $n, int $decimals = 4): string
    {
        if (is_int($n)) {
            return (string) $n;
        }
        if (floor($n) === $n && abs($n) < 1.0e15) {
            return (string) (int) $n;
        }
        $s = number_format($n, $decimals, decimal_separator: '.', thousands_separator: '');
        $s = rtrim(rtrim($s, characters: '0'), characters: '.');

        return '-0' === $s || '' === $s ? '0' : $s;
    }

    /**
     * Formats a list of numbers separated by single spaces.
     *
     * @param list<float|int> $numbers
     */
    public static function list(array $numbers, int $decimals = 4): string
    {
        $parts = [];
        foreach ($numbers as $n) {
            $parts[] = self::format($n, $decimals);
        }

        return implode(' ', $parts);
    }
}
