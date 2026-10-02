<?php

declare(strict_types=1);

namespace Php2Pdf\Core;

use Php2Pdf\Exception\InvalidArgumentException;

use function abs;
use function floor;
use function implode;
use function is_finite;
use function is_int;
use function is_nan;
use function number_format;
use function rtrim;
use function sprintf;

/**
 * Number formatting for PDF syntax: no exponents, no trailing zeros, no negative zero.
 *
 * @internal
 */
final class Num
{
    /** @throws InvalidArgumentException for NaN or infinity, which PDF syntax cannot express */
    public static function format(float|int $n, int $decimals = 4): string
    {
        if (is_int($n)) {
            return (string) $n;
        }
        if (! is_finite($n)) {
            throw new InvalidArgumentException(sprintf(
                '%s cannot be written into a PDF.',
                match (true) {
                    is_nan($n) => 'NAN',
                    $n > 0.0 => 'INF',
                    default => '-INF',
                },
            ));
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
