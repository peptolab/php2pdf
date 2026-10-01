<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use function is_numeric;
use function preg_match;
use function preg_match_all;
use function strtolower;
use function trim;

/**
 * SVG lengths in CSS user units (px at 96 dpi). Relative units resolve against a reference
 * length (percentages) or the current font size (em, ex).
 *
 * @internal
 */
final class Length
{
    /** Points per CSS pixel. */
    public const float PT_PER_PX = 0.75;

    private const array UNITS = [
        'px' => 1.0,
        'pt' => 4.0 / 3.0,
        'pc' => 16.0,
        'mm' => 96.0 / 25.4,
        'cm' => 96.0 / 2.54,
        'in' => 96.0,
    ];

    /**
     * Parses a whitespace- or comma-separated list of numbers.
     *
     * @return list<float>
     */
    public static function numbers(?string $value): array
    {
        if (null === $value) {
            return [];
        }
        $m     = [];
        $found = preg_match_all('/[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/', $value, $m);
        if (false === $found || 0 === $found) {
            return [];
        }
        $out = [];
        foreach ($m[0] ?? [] as $number) {
            $out[] = self::toFloat($number);
        }

        return $out;
    }

    /** Parses a length; returns null for missing, "auto" or malformed values. */
    public static function parse(?string $value, float $reference = 0.0, float $fontSize = 16.0): ?float
    {
        if (null === $value) {
            return null;
        }
        $value = strtolower(trim($value));
        if ('' === $value || 'auto' === $value) {
            return null;
        }
        if (is_numeric($value)) {
            return (float) $value;
        }
        $m = [];
        if (
            preg_match('/^([+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)\s*(px|pt|pc|mm|cm|in|em|ex|%)$/', $value, $m) !== 1
        ) {
            return null;
        }
        $number = self::toFloat($m[1] ?? '0');

        return match ($m[2] ?? '') {
            '%'     => ($number * $reference) / 100.0,
            'em'    => $number * $fontSize,
            'ex'    => ($number * $fontSize) / 2.0,
            default => $number * (self::UNITS[$m[2] ?? 'px'] ?? 1.0),
        };
    }

    /** A numeric string as a float; anything else is 0. */
    public static function toFloat(string $value): float
    {
        return is_numeric($value) ? (float) $value : 0.0;
    }
}
