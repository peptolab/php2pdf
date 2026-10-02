<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use Closure;
use IntlChar;
use Normalizer;

use function array_splice;
use function array_values;
use function class_exists;
use function count;
use function in_array;
use function mb_chr;
use function mb_ord;
use function mb_str_split;
use function mb_strlen;
use function preg_match;
use function usort;

/**
 * Normalises text for a font as HarfBuzz does before shaping. A character the font lacks is
 * decomposed into characters it has; a character followed by combining marks is decomposed with
 * them, the marks are put in canonical order, and each mark is composed back into the character
 * before it where Unicode composes the two and the font has a glyph for the result. So "e" with a
 * combining acute uses the font's "é", and an accented letter the font lacks still shows as a
 * letter and a mark. Needs ext-intl; without it the text is left as it is.
 *
 * @internal
 */
final class Normaliser
{
    /** Arabic modifier combining marks, which HarfBuzz moves before the other marks of their class. */
    private const array MODIFIERS = [
        0x0654,
        0x0655,
        0x0658,
        0x06DC,
        0x06E3,
        0x06E7,
        0x06E8,
        0x08CA,
        0x08CB,
        0x08CD,
        0x08CE,
        0x08CF,
        0x08D3,
        0x08F3,
    ];

    /** HarfBuzz's modified combining classes: Unicode class => the class marks are ordered by. */
    private const array MODIFIED_CLASSES = [
        10  => 22,
        11  => 15,
        12  => 16,
        13  => 17,
        14  => 23,
        15  => 18,
        16  => 19,
        17  => 20,
        18  => 21,
        19  => 14,
        20  => 24,
        21  => 12,
        22  => 25,
        23  => 13,
        24  => 10,
        25  => 11,
        26  => 26,
        27  => 28,
        28  => 29,
        29  => 30,
        30  => 31,
        31  => 32,
        32  => 33,
        33  => 27,
        34  => 34,
        35  => 35,
        36  => 36,
        84  => 0,
        91  => 0,
        103 => 3,
        107 => 107,
        118 => 118,
        122 => 122,
        129 => 129,
        130 => 132,
        132 => 131,
    ];

    /**
     * @param list<int>             $codePoints
     * @param Closure(int): bool    $hasGlyph
     *
     * @return list<int>
     */
    public static function normalise(array $codePoints, Closure $hasGlyph): array
    {
        if (! class_exists(Normalizer::class) || ! class_exists(IntlChar::class)) {
            return $codePoints;
        }
        $out   = [];
        $count = count($codePoints);
        $marks = false;
        for ($i = 0; $i < $count; ++$i) {
            $codePoint = $codePoints[$i] ?? 0;
            $cluster   = self::isMark($codePoints[$i + 1] ?? -1);
            if (! $cluster && $hasGlyph($codePoint)) {
                $out[] = $codePoint;
                continue;
            }
            $decomposed = self::decompose($codePoint, $hasGlyph, shortest: ! $cluster);
            foreach ($decomposed ?? [$codePoint] as $part) {
                $out[] = $part;
            }
            if (! $cluster) {
                continue;
            }
            $marks = true;
            while (self::isMark($codePoints[$i + 1] ?? -1)) {
                ++$i;
                $mark = $codePoints[$i] ?? 0;
                foreach (self::decompose($mark, $hasGlyph, shortest: false) ?? [$mark] as $part) {
                    $out[] = $part;
                }
            }
        }

        return $marks ? self::recompose(self::reorder($out), $hasGlyph) : $out;
    }

    /**
     * The combining class marks are ordered by: Unicode's, with HarfBuzz's changes for Hebrew
     * (shin and sin dots and dagesh before vowels), Arabic (shadda first), Telugu, Thai, Lao and
     * Tibetan, so marks reach the font in the order its lookups expect.
     */
    private static function combiningClass(int $codePoint): int
    {
        $class = (int) IntlChar::getCombiningClass($codePoint);

        return self::MODIFIED_CLASSES[$class] ?? $class;
    }

    /** The character Unicode composes two into, or null. */
    private static function compose(int $a, int $b): ?int
    {
        $composed = Normalizer::normalize(
            mb_chr($a, encoding: 'UTF-8') . mb_chr($b, encoding: 'UTF-8'),
            Normalizer::FORM_C,
        );
        if (false === $composed || 1 !== mb_strlen($composed, encoding: 'UTF-8')) {
            return null;
        }
        $codePoint = mb_ord($composed, encoding: 'UTF-8');

        return false === $codePoint ? null : $codePoint;
    }

    /**
     * A character's canonical decomposition into characters the font has, one step at a time: the
     * first characters the font has (shortest), or as far as it goes. Null when it does not
     * decompose that way.
     *
     * @param Closure(int): bool $hasGlyph
     *
     * @return list<int>|null
     */
    private static function decompose(int $codePoint, Closure $hasGlyph, bool $shortest): ?array
    {
        $raw = Normalizer::getRawDecomposition(mb_chr($codePoint, encoding: 'UTF-8'), Normalizer::FORM_D);
        if (null === $raw || '' === $raw) {
            return null;
        }
        $parts = [];
        foreach (mb_str_split($raw, length: 1, encoding: 'UTF-8') as $char) {
            $parts[] = (int) mb_ord($char, encoding: 'UTF-8');
        }
        $a = $parts[0] ?? 0;
        $b = $parts[1] ?? null;
        if (null !== $b && ! $hasGlyph($b)) {
            return null;
        }
        $tail = null === $b ? [] : [$b];
        $hasA = $hasGlyph($a);
        if ($shortest && $hasA) {
            return [$a, ...$tail];
        }
        $deeper = self::decompose($a, $hasGlyph, $shortest);
        if (null !== $deeper) {
            return [...$deeper, ...$tail];
        }

        return $hasA ? [$a, ...$tail] : null;
    }

    private static function isMark(int $codePoint): bool
    {
        return $codePoint >= 0 && 1 === preg_match('/^\p{M}$/u', mb_chr($codePoint, encoding: 'UTF-8'));
    }

    /**
     * Arabic modifier combining marks (hamza above and below, and the like) moved to the start of
     * their marks of combining class 220 and 230, as HarfBuzz's Arabic shaper orders them, so they
     * reach the font next to the letter they modify.
     *
     * @param list<int> $marks in canonical order
     *
     * @return list<int>
     */
    private static function modifiersFirst(array $marks): array
    {
        $count = count($marks);
        $start = 0;
        $i     = 0;
        foreach ([220, 230] as $class) {
            while ($i < $count && self::combiningClass($marks[$i] ?? 0) < $class) {
                ++$i;
            }
            if ($i === $count) {
                break;
            }
            $j = $i;
            while (
                $j < $count
                && $class === self::combiningClass($marks[$j] ?? 0)
                && in_array($marks[$j] ?? 0, self::MODIFIERS, strict: true)
            ) {
                ++$j;
            }
            if ($i === $j) {
                continue;
            }
            $moved = array_splice($marks, $i, $j - $i);
            array_splice($marks, $start, length: 0, replacement: $moved);
            $start += $j - $i;
            $i     = $j;
        }

        return $marks;
    }

    /**
     * Composes each mark into the character before it, unless a mark between them has a combining
     * class as high, when Unicode composes them and the font has the composed glyph.
     *
     * @param list<int>          $codePoints
     * @param Closure(int): bool $hasGlyph
     *
     * @return list<int>
     */
    private static function recompose(array $codePoints, Closure $hasGlyph): array
    {
        $out     = [];
        $starter = 0;
        foreach ($codePoints as $codePoint) {
            $last = count($out) - 1;
            if ($last >= 0 && self::isMark($codePoint)) {
                $previous = $out[$last] ?? 0;
                $composed = $starter === $last || self::combiningClass($previous) < self::combiningClass($codePoint)
                    ? self::compose($out[$starter] ?? 0, $codePoint)
                    : null;
                if (null !== $composed && $hasGlyph($composed)) {
                    $out[$starter] = $composed;
                    continue;
                }
            }
            $out[] = $codePoint;
            if (0 === self::combiningClass($codePoint)) {
                $starter = count($out) - 1;
            }
        }

        return array_values($out);
    }

    /**
     * Puts each run of combining marks in canonical order: by combining class, keeping the order
     * of marks of the same class.
     *
     * @param list<int> $codePoints
     *
     * @return list<int>
     */
    private static function reorder(array $codePoints): array
    {
        $out   = [];
        $count = count($codePoints);
        for ($i = 0; $i < $count;) {
            $codePoint = $codePoints[$i] ?? 0;
            if (0 === self::combiningClass($codePoint)) {
                $out[] = $codePoint;
                ++$i;
                continue;
            }
            $run = [];
            while ($i < $count && 0 !== self::combiningClass($codePoints[$i] ?? 0)) {
                $run[] = [$codePoints[$i] ?? 0, count($run)];
                ++$i;
            }
            usort(
                $run,
                /**
                 * @param array{0: int, 1: int} $x
                 * @param array{0: int, 1: int} $y
                 */
                static fn(array $x, array $y): int => (
                    [self::combiningClass($x[0]), $x[1]] <=> [self::combiningClass($y[0]), $y[1]]
                ),
            );
            $marks = [];
            foreach ($run as [$mark]) {
                $marks[] = $mark;
            }
            foreach (self::modifiersFirst($marks) as $mark) {
                $out[] = $mark;
            }
        }

        return $out;
    }
}
