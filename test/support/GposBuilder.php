<?php

declare(strict_types=1);

namespace Php2PdfTest\Support;

use function array_keys;
use function array_map;
use function array_values;
use function count;
use function implode;
use function ksort;
use function max;
use function pack;
use function strlen;

/**
 * GPOS subtables for tests, written into a table by GsubBuilder::build() with extension type 9:
 * single and pair adjustment, mark-to-base, mark-to-ligature and mark-to-mark attachment. Value
 * records here carry x placement, y placement and x advance (value format 0x0007).
 */
final class GposBuilder
{
    public const int VALUES = 0x0007;

    /**
     * Mark-to-base: marks as glyph => (class, anchor), bases as glyph => class => anchor.
     *
     * @param array<int, array{0: int, 1: array{0: int, 1: int}}> $marks
     * @param array<int, array<int, array{0: int, 1: int}>>       $bases
     */
    public static function markToBase(array $marks, array $bases): string
    {
        return self::attachment(4, $marks, self::anchorRows($bases, self::classCount($marks)), array_keys($bases));
    }

    /**
     * Mark-to-ligature: ligatures as glyph => component => class => anchor.
     *
     * @param array<int, array{0: int, 1: array{0: int, 1: int}}>       $marks
     * @param array<int, list<array<int, array{0: int, 1: int}>>>       $ligatures
     */
    public static function markToLigature(array $marks, array $ligatures): string
    {
        ksort($ligatures);
        $classes = self::classCount($marks);
        $tables  = '';
        $offsets = [];
        $base    = 2 + (2 * count($ligatures));
        foreach ($ligatures as $components) {
            $offsets[] = $base + strlen($tables);
            $tables    .= self::anchorRows($components, $classes);
        }
        $array = pack('n', count($ligatures)) . pack('n*', ...$offsets) . $tables;

        return self::attachment(5, $marks, $array, array_keys($ligatures));
    }

    /**
     * Mark-to-mark: the attaching marks, and the marks they attach to as glyph => class => anchor.
     *
     * @param array<int, array{0: int, 1: array{0: int, 1: int}}> $marks
     * @param array<int, array<int, array{0: int, 1: int}>>       $targets
     */
    public static function markToMark(array $marks, array $targets): string
    {
        return self::attachment(6, $marks, self::anchorRows($targets, self::classCount($marks)), array_keys($targets));
    }

    /**
     * Pair adjustment by glyph: first => second => (first's values, second's values).
     *
     * @param array<int, array<int, array{0: array{0: int, 1: int, 2: int}, 1: array{0: int, 1: int, 2: int}}>> $pairs
     */
    public static function pair1(array $pairs, int $format2 = 0): string
    {
        ksort($pairs);
        $sets = [];
        foreach ($pairs as $seconds) {
            ksort($seconds);
            $records = '';
            foreach ($seconds as $second => [$values1, $values2]) {
                $records .=
                    pack('n', $second) . self::values($values1, self::VALUES) . self::values($values2, $format2);
            }
            $sets[] = pack('n', count($seconds)) . $records;
        }
        $header   = 10 + (2 * count($sets));
        $coverage = GsubBuilder::coverage(array_keys($pairs));
        $offsets  = [];
        $body     = '';
        foreach ($sets as $set) {
            $offsets[] = $header + strlen($coverage) + strlen($body);
            $body      .= $set;
        }

        return (
            pack('nnnnn', 1, $header, self::VALUES, $format2, count($sets))
                . pack('n*', ...$offsets)
                . $coverage
                . $body
        );
    }

    /**
     * Pair adjustment by class: the first glyph's values for each class pair.
     *
     * @param list<int>                                         $coverage
     * @param array<int, int>                                   $classes1
     * @param array<int, int>                                   $classes2
     * @param array<int, array<int, array{0: int, 1: int, 2: int}>> $values class1 => class2 => values
     */
    public static function pair2(array $coverage, array $classes1, array $classes2, array $values): string
    {
        $count1  = max([0, ...array_values($classes1)]) + 1;
        $count2  = max([0, ...array_values($classes2)]) + 1;
        $records = '';
        for ($c1 = 0; $c1 < $count1; ++$c1) {
            for ($c2 = 0; $c2 < $count2; ++$c2) {
                $records .= self::values($values[$c1][$c2] ?? [0, 0, 0], self::VALUES);
            }
        }
        $header = 16 + strlen($records);
        $cov    = GsubBuilder::coverage($coverage);
        $def1   = GsubBuilder::classDef($classes1);
        $def2   = GsubBuilder::classDef($classes2);

        return (
            pack(
                'nnnnnnnn',
                2,
                $header,
                self::VALUES,
                0,
                $header + strlen($cov),
                $header + strlen($cov) + strlen($def1),
                $count1,
                $count2,
            )
                . $records
                . $cov
                . $def1
                . $def2
        );
    }

    /**
     * Single adjustment, format 1: the same values for every covered glyph.
     *
     * @param list<int>                    $glyphs
     * @param array{0: int, 1: int, 2: int} $values
     */
    public static function single1(array $glyphs, array $values): string
    {
        return (
            pack('nnn', 1, 6 + 6, self::VALUES) . self::values($values, self::VALUES) . GsubBuilder::coverage($glyphs)
        );
    }

    /**
     * Single adjustment, format 2: values per glyph.
     *
     * @param array<int, array{0: int, 1: int, 2: int}> $values
     */
    public static function single2(array $values): string
    {
        ksort($values);
        $records = implode('', array_map(static fn(array $v): string => self::values(
            $v,
            self::VALUES,
        ), array_values($values)));

        return (
            pack('nnnn', 2, 8 + strlen($records), self::VALUES, count($values))
                . $records
                . GsubBuilder::coverage(array_keys($values))
        );
    }

    /** @param array{0: int, 1: int} $anchor */
    private static function anchor(array $anchor): string
    {
        return pack('nnn', 1, $anchor[0] & 0xFFFF, $anchor[1] & 0xFFFF);
    }

    /**
     * Rows of anchors, one row per glyph or component in key order, one anchor offset per class
     * (0 where a class has none), offsets from the start of the rows' table.
     *
     * @param array<int, array<int, array{0: int, 1: int}>> $rows
     */
    private static function anchorRows(array $rows, int $classes): string
    {
        ksort($rows);
        $header  = 2 + (2 * count($rows) * $classes);
        $offsets = [];
        $anchors = '';
        foreach ($rows as $row) {
            for ($class = 0; $class < $classes; ++$class) {
                $anchor = $row[$class] ?? null;
                if (null === $anchor) {
                    $offsets[] = 0;
                    continue;
                }
                $offsets[] = $header + strlen($anchors);
                $anchors   .= self::anchor($anchor);
            }
        }

        return pack('n', count($rows)) . ([] === $offsets ? '' : pack('n*', ...$offsets)) . $anchors;
    }

    /**
     * An attachment subtable: mark coverage, the other coverage, class count, mark array and the
     * other array.
     *
     * @param array<int, array{0: int, 1: array{0: int, 1: int}}> $marks
     * @param list<int>                                           $others
     */
    private static function attachment(int $type, array $marks, string $otherArray, array $others): string
    {
        ksort($marks);
        $classes   = self::classCount($marks);
        $header    = 12;
        $markCov   = GsubBuilder::coverage(array_keys($marks));
        $otherCov  = GsubBuilder::coverage($others);
        $records   = '';
        $anchors   = '';
        $arrayHead = 2 + (4 * count($marks));
        foreach ($marks as [$class, $anchor]) {
            $records .= pack('nn', $class, $arrayHead + strlen($anchors));
            $anchors .= self::anchor($anchor);
        }
        $markArray = pack('n', count($marks)) . $records . $anchors;
        $at        = $header;
        $offsets   = [];
        foreach ([$markCov, $otherCov, $markArray, $otherArray] as $table) {
            $offsets[] = $at;
            $at        += strlen($table);
        }

        return (
            pack('nnnnnn', 1, $offsets[0], $offsets[1], $classes, $offsets[2], $offsets[3])
                . $markCov
                . $otherCov
                . $markArray
                . $otherArray
        );
    }

    /** @param array<int, array{0: int, 1: array{0: int, 1: int}}> $marks */
    private static function classCount(array $marks): int
    {
        $count = 0;
        foreach ($marks as [$class]) {
            $count = max($count, $class + 1);
        }

        return $count;
    }

    /** @param array{0: int, 1: int, 2: int} $values */
    private static function values(array $values, int $format): string
    {
        return 0 === $format ? '' : pack('nnn', $values[0] & 0xFFFF, $values[1] & 0xFFFF, $values[2] & 0xFFFF);
    }
}
