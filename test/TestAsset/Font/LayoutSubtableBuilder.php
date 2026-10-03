<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset\Font;

use Php2PdfTest\TestAsset\GsubBuilder;

use function array_keys;
use function count;
use function ksort;
use function max;
use function pack;
use function strlen;

/**
 * GSUB and GPOS subtables GsubBuilder and GposBuilder do not write: context substitution by
 * class (format 2), class pair adjustment with class counts of its own, and subtables that
 * cover glyphs but list nothing for them, for the lookups that must then leave them alone.
 */
final class LayoutSubtableBuilder
{
    /**
     * Context, format 2: the glyphs the input may start with, one class definition for the whole
     * input, and rules by the first glyph's class of (the rest of the input's classes,
     * substitution records).
     *
     * @param list<int>                                                                 $coverage
     * @param array<int, int>                                                           $classes
     * @param array<int, list<array{0: list<int>, 1: list<array{0: int, 1: int}>}>> $rules
     */
    public static function context2(array $coverage, array $classes, array $rules): string
    {
        ksort($rules);
        $setCount   = ([] === $rules ? 0 : max(array_keys($rules))) + 1;
        $headerSize = 8 + (2 * $setCount);
        $body       = GsubBuilder::coverage($coverage);
        $classDefAt = $headerSize + strlen($body);
        $body       .= GsubBuilder::classDef($classes);
        $setOffsets = [];
        for ($class = 0; $class < $setCount; ++$class) {
            $set = $rules[$class] ?? null;
            if (null === $set) {
                $setOffsets[] = 0;
                continue;
            }
            $setOffsets[] = $headerSize + strlen($body);
            $body         .= self::ruleSet($set);
        }

        return pack('nnnn', 2, $headerSize, $classDefAt, $setCount) . pack('n*', ...$setOffsets) . $body;
    }

    /**
     * A subtable of the given format whose coverage offset is followed by $fields, then the
     * coverage: lookups find the glyphs it covers, and nothing after the fields.
     *
     * @param list<int> $glyphs
     */
    public static function covered(int $format, array $glyphs, int ...$fields): string
    {
        return (
            pack('nn', $format, 4 + (2 * count($fields)))
                . ([] === $fields ? '' : pack('n*', ...$fields))
                . GsubBuilder::coverage($glyphs)
        );
    }

    /**
     * Pair adjustment by class with no values, declaring its own class counts, which may leave
     * classes the definitions give out of range.
     *
     * @param list<int>       $coverage
     * @param array<int, int> $classes1
     * @param array<int, int> $classes2
     */
    public static function pairClasses(
        array $coverage,
        array $classes1,
        array $classes2,
        int $count1,
        int $count2,
    ): string {
        $cov  = GsubBuilder::coverage($coverage);
        $def1 = GsubBuilder::classDef($classes1);

        return (
            pack('nnnnnnnn', 2, 16, 0, 0, 16 + strlen($cov), 16 + strlen($cov) + strlen($def1), $count1, $count2)
                . $cov
                . $def1
                . GsubBuilder::classDef($classes2)
        );
    }

    /** @param list<array{0: list<int>, 1: list<array{0: int, 1: int}>}> $rules */
    private static function ruleSet(array $rules): string
    {
        $tables  = '';
        $offsets = [];
        $base    = 2 + (2 * count($rules));
        foreach ($rules as [$input, $records]) {
            $offsets[] = $base + strlen($tables);
            $tables    .= pack('nn', count($input) + 1, count($records));
            foreach ($input as $class) {
                $tables .= pack('n', $class);
            }
            foreach ($records as [$sequenceIndex, $lookupIndex]) {
                $tables .= pack('nn', $sequenceIndex, $lookupIndex);
            }
        }

        return pack('n', count($rules)) . ([] === $offsets ? '' : pack('n*', ...$offsets)) . $tables;
    }
}
