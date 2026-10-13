<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Style;

use function chr;
use function intdiv;
use function strtoupper;

/**
 * What marks the items of a list: a bullet, or a number in one of the usual counting styles,
 * followed by a full stop. The values are those of HTML's type attribute, which markup lists take.
 *
 * @api
 */
enum ListMarker: string
{
    case Disc = 'disc';

    case Circle = 'circle';

    case Square = 'square';

    case Dash = 'dash';

    case Decimal = '1';

    case LowerAlpha = 'a';

    case UpperAlpha = 'A';

    case LowerRoman = 'i';

    case UpperRoman = 'I';

    /** The bullet of a list nested this deep (1 for a list of its own): disc, circle, square, and round again. */
    public static function bullet(int $depth): self
    {
        return match (($depth - 1) % 3) {
            1       => self::Circle,
            2       => self::Square,
            default => self::Disc,
        };
    }

    /** Letters counting a, b ... z, aa, ab: a number above 0. */
    private static function alpha(int $number): string
    {
        $out = '';
        for (; $number > 0; $number = intdiv($number - 1, num2: 26)) {
            $out = chr(97 + (($number - 1) % 26)) . $out;
        }

        return $out;
    }

    /** Roman numerals in lower case: a number from 1 to 3999. */
    private static function roman(int $number): string
    {
        $out = '';
        foreach ([
            [1000, 'm'],
            [900, 'cm'],
            [500, 'd'],
            [400, 'cd'],
            [100, 'c'],
            [90, 'xc'],
            [50, 'l'],
            [40, 'xl'],
            [10, 'x'],
            [9, 'ix'],
            [5, 'v'],
            [4, 'iv'],
            [1, 'i'],
        ] as [$value, $numeral]) {
            for (; $number >= $value; $number -= $value) {
                $out .= $numeral;
            }
        }

        return $out;
    }

    /** Whether the marker counts the items rather than bulleting them. */
    public function isOrdered(): bool
    {
        return match ($this) {
            self::Disc, self::Circle, self::Square, self::Dash => false,
            default => true,
        };
    }

    /**
     * The label of an item: the bullet, or its number and a full stop. A number letters or
     * Roman numerals cannot count (0 or less, or 4000 and above in Roman) is written in digits.
     */
    public function label(int $number): string
    {
        $alpha = $number > 0;
        $roman = $number > 0 && $number < 4000;

        return match ($this) {
            self::Disc => "\u{2022}",
            self::Circle => "\u{25E6}",
            self::Square => "\u{25AA}",
            self::Dash => "\u{2013}",
            self::LowerAlpha => ($alpha ? self::alpha($number) : (string) $number) . '.',
            self::UpperAlpha => ($alpha ? strtoupper(self::alpha($number)) : (string) $number) . '.',
            self::LowerRoman => ($roman ? self::roman($number) : (string) $number) . '.',
            self::UpperRoman => ($roman ? strtoupper(self::roman($number)) : (string) $number) . '.',
            self::Decimal => "{$number}.",
        };
    }

    /** How tagged PDF names the numbering of a list with this marker (its ListNumbering attribute). */
    public function numbering(): string
    {
        return match ($this) {
            self::Disc => 'Disc',
            self::Circle => 'Circle',
            self::Square => 'Square',
            self::Dash => 'None',
            self::Decimal => 'Decimal',
            self::LowerAlpha => 'LowerAlpha',
            self::UpperAlpha => 'UpperAlpha',
            self::LowerRoman => 'LowerRoman',
            self::UpperRoman => 'UpperRoman',
        };
    }
}
