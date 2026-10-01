<?php

declare(strict_types=1);

namespace PhpPdf\Core;

/**
 * Number formatting for PDF syntax: no exponents, no trailing zeros, no negative zero.
 */
final class Num
{
    public static function format(float|int $n, int $decimals = 4): string
    {
        if (is_int($n)) {
            return (string) $n;
        }
        if ($n === floor($n) && abs($n) < 1.0e15) {
            return (string) (int) $n;
        }
        $s = rtrim(rtrim(number_format($n, $decimals, '.', ''), '0'), '.');

        return $s === '-0' || $s === '' ? '0' : $s;
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
