<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset;

use function array_key_exists;
use function array_keys;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function ksort;
use function max;
use function pack;
use function sort;
use function strlen;
use function substr;

/**
 * A minimal GSUB table: one script with a default language system whose features (and an
 * optional required feature) point at lookups, each a type, its subtables, optionally wrapped in
 * extension lookups (type 7), and a lookup flag with a mark filtering set. The subtable writers
 * cover single, multiple, alternate, ligature, context, chained context and reverse chaining
 * substitution; gdef() writes the glyph definitions lookup flags consult.
 */
final class GsubBuilder
{
    /**
     * Alternate substitution: glyph => its alternates.
     *
     * @param array<int, list<int>> $alternates
     */
    public static function alternate(array $alternates): string
    {
        ksort($alternates);
        $sets = array_map(
            /** @param list<int> $list */
            static fn(array $list): string => pack('n', count($list)) . pack('n*', ...$list),
            $alternates,
        );

        return self::withSets(1, array_keys($alternates), $sets);
    }

    /**
     * @param array<string, list<int>>                                         $features feature tag => lookup indices
     * @param list<array{0: int, 1: list<string>, 2?: bool, 3?: int, 4?: int}> $lookups  type, subtables, wrapped in
     *                                                                                   extensions, lookup flag, mark
     *                                                                                   filtering set
     * @param string|null                                                      $required the feature that is required
     * @param int                                                              $extensionType 7 for GSUB, 9 for GPOS
     */
    public static function build(
        string $script,
        array $features,
        array $lookups,
        ?string $required = null,
        int $extensionType = 7,
    ): string {
        $scriptList  = self::scriptList($script, $features, $required);
        $featureList = self::featureList($features);
        $scriptAt    = 10;
        $featureAt   = $scriptAt + strlen($scriptList);
        $lookupAt    = $featureAt + strlen($featureList);

        return (
            pack('nnnnn', 1, 0, $scriptAt, $featureAt, $lookupAt)
                . $scriptList
                . $featureList
                . self::lookupList($lookups, $extensionType)
        );
    }

    /**
     * Chained context, format 1: first glyph => rules of (backtrack, rest of input, lookahead,
     * substitution records).
     *
     * @param array<int, list<array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<array{0: int, 1: int}>}>> $rules
     */
    public static function chain1(array $rules): string
    {
        ksort($rules);
        $sets = array_map(self::ruleSet(...), $rules);

        return self::withSets(1, array_keys($rules), $sets);
    }

    /**
     * Chained context, format 2: glyph classes for backtrack, input and lookahead, the glyphs the
     * input may start with, and rules by input class (classes instead of glyphs).
     *
     * @param list<int>                                                                                          $coverage
     * @param array<int, int>                                                                                    $backtrackClasses
     * @param array<int, int>                                                                                    $inputClasses
     * @param array<int, int>                                                                                    $lookaheadClasses
     * @param array<int, list<array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<array{0: int, 1: int}>}>> $rules
     */
    public static function chain2(
        array $coverage,
        array $backtrackClasses,
        array $inputClasses,
        array $lookaheadClasses,
        array $rules,
    ): string {
        $classCount = max([0, ...array_keys($rules)]) + 1;
        $tables     = [
            self::coverage($coverage),
            self::classDef($backtrackClasses),
            self::classDef($inputClasses),
            self::classDef($lookaheadClasses),
        ];
        $sets = [];
        for ($class = 0; $class < $classCount; ++$class) {
            $sets[] = array_key_exists($class, $rules) ? self::ruleSet($rules[$class]) : null;
        }
        $headerSize = 12 + (2 * $classCount);
        $offsets    = [];
        $body       = '';
        foreach ($tables as $table) {
            $offsets[] = $headerSize + strlen($body);
            $body      .= $table;
        }
        $setOffsets = [];
        foreach ($sets as $set) {
            $setOffsets[] = null === $set ? 0 : $headerSize + strlen($body);
            $body         .= $set ?? '';
        }

        return pack('n', 2) . pack('n*', ...$offsets) . pack('n', $classCount) . pack('n*', ...$setOffsets) . $body;
    }

    /**
     * Chained context, format 3: one coverage per position.
     *
     * @param list<list<int>>               $backtrack
     * @param list<list<int>>               $input
     * @param list<list<int>>               $lookahead
     * @param list<array{0: int, 1: int}>   $records
     */
    public static function chain3(array $backtrack, array $input, array $lookahead, array $records): string
    {
        $all        = [...$backtrack, ...$input, ...$lookahead];
        $headerSize = 2
        + 2
        + (2 * count($backtrack))
        + 2
        + (2 * count($input))
        + 2
        + (2 * count($lookahead))
        + 2
        + (4 * count($records));
        $offsets   = [];
        $coverages = '';
        foreach ($all as $glyphs) {
            $offsets[] = $headerSize + strlen($coverages);
            $coverages .= self::coverage($glyphs);
        }
        $b = count($backtrack);
        $n = count($input);

        return (
            pack('nn', 3, $b)
                . self::shorts(array_slice($offsets, offset: 0, length: $b))
                . pack('n', $n)
                . self::shorts(array_slice($offsets, $b, $n))
                . pack('n', count($lookahead))
                . self::shorts(array_slice($offsets, $b + $n))
                . self::records($records)
                . $coverages
        );
    }

    /** @param array<int, int> $classes glyph => class */
    public static function classDef(array $classes): string
    {
        ksort($classes);
        $records = '';
        foreach ($classes as $glyph => $class) {
            $records .= pack('nnn', $glyph, $glyph, $class);
        }

        return pack('nn', 2, count($classes)) . $records;
    }

    /**
     * Context, format 1: first glyph => rules of (rest of input, substitution records).
     *
     * @param array<int, list<array{0: list<int>, 1: list<array{0: int, 1: int}>}>> $rules
     */
    public static function context1(array $rules): string
    {
        ksort($rules);
        $sets = [];
        foreach ($rules as $set) {
            $tables  = '';
            $offsets = [];
            $base    = 2 + (2 * count($set));
            foreach ($set as [$input, $records]) {
                $offsets[] = $base + strlen($tables);
                $tables    .= pack('nn', count($input) + 1, count($records))
                . self::shorts($input)
                . substr(self::records($records), offset: 2);
            }
            $sets[] = pack('n', count($set)) . self::shorts($offsets) . $tables;
        }

        return self::withSets(1, array_keys($rules), $sets);
    }

    /**
     * Context, format 3: one coverage per input position.
     *
     * @param list<list<int>>             $input
     * @param list<array{0: int, 1: int}> $records
     */
    public static function context3(array $input, array $records): string
    {
        $headerSize = 6 + (2 * count($input)) + (4 * count($records));
        $offsets    = [];
        $coverages  = '';
        foreach ($input as $glyphs) {
            $offsets[] = $headerSize + strlen($coverages);
            $coverages .= self::coverage($glyphs);
        }

        return (
            pack('nnn', 3, count($input), count($records))
                . self::shorts($offsets)
                . substr(self::records($records), offset: 2)
                . $coverages
        );
    }

    /** @param list<int> $glyphs */
    public static function coverage(array $glyphs): string
    {
        sort($glyphs);

        return pack('nn', 1, count($glyphs)) . self::shorts($glyphs);
    }

    /**
     * Glyph definitions: glyph classes (1 base, 2 ligature, 3 mark), mark attachment classes and
     * mark glyph sets.
     *
     * @param array<int, int> $classes
     * @param array<int, int> $attachClasses
     * @param list<list<int>> $markSets
     */
    public static function gdef(array $classes, array $attachClasses = [], array $markSets = []): string
    {
        $glyphClasses = self::classDef($classes);
        $attach       = self::classDef($attachClasses);
        $setsAt       = 14 + strlen($glyphClasses) + strlen($attach);
        $sets         = '';
        $setOffsets   = [];
        $setBase      = 4 + (4 * count($markSets));
        foreach ($markSets as $set) {
            $setOffsets[] = pack('N', $setBase + strlen($sets));
            $sets         .= self::coverage($set);
        }

        return (
            pack('nnnnnnn', 1, 2, 14, 0, 0, 14 + strlen($glyphClasses), [] === $markSets ? 0 : $setsAt)
                . $glyphClasses
                . $attach
                . ([] === $markSets ? '' : pack('nn', 1, count($markSets)) . implode('', $setOffsets) . $sets)
        );
    }

    /**
     * Ligature substitution: first glyph => (other components, ligature).
     *
     * @param array<int, list<array{0: list<int>, 1: int}>> $ligatures
     */
    public static function ligature(array $ligatures): string
    {
        ksort($ligatures);
        $sets = [];
        foreach ($ligatures as $entries) {
            $tables  = '';
            $offsets = [];
            $base    = 2 + (2 * count($entries));
            foreach ($entries as [$components, $ligature]) {
                $offsets[] = $base + strlen($tables);
                $tables    .= pack('nn', $ligature, count($components) + 1) . self::shorts($components);
            }
            $sets[] = pack('n', count($entries)) . self::shorts($offsets) . $tables;
        }

        return self::withSets(1, array_keys($ligatures), $sets);
    }

    /**
     * Multiple substitution: glyph => the glyphs it becomes.
     *
     * @param array<int, list<int>> $sequences
     */
    public static function multiple(array $sequences): string
    {
        ksort($sequences);
        $sets = array_map(
            /** @param list<int> $glyphs */
            static fn(array $glyphs): string => pack('n', count($glyphs)) . self::shorts($glyphs),
            $sequences,
        );

        return self::withSets(1, array_keys($sequences), array_values($sets));
    }

    /**
     * Reverse chaining single substitution: glyph => substitute, with backtrack and lookahead
     * coverages.
     *
     * @param array<int, int> $map
     * @param list<list<int>> $backtrack
     * @param list<list<int>> $lookahead
     */
    public static function reverse(array $map, array $backtrack, array $lookahead): string
    {
        ksort($map);
        $tables = [self::coverage(array_keys($map))];
        foreach ([...$backtrack, ...$lookahead] as $glyphs) {
            $tables[] = self::coverage($glyphs);
        }
        $headerSize = 6 + (2 * count($backtrack)) + 2 + (2 * count($lookahead)) + 2 + (2 * count($map));
        $offsets    = [];
        $body       = '';
        foreach ($tables as $table) {
            $offsets[] = $headerSize + strlen($body);
            $body      .= $table;
        }
        $b = count($backtrack);

        return (
            pack('nn', 1, $offsets[0])
                . pack('n', $b)
                . self::shorts(array_slice($offsets, offset: 1, length: $b))
                . pack('n', count($lookahead))
                . self::shorts(array_slice($offsets, 1 + $b))
                . pack('n', count($map))
                . self::shorts(array_values($map))
                . $body
        );
    }

    /**
     * Single substitution, format 1: every covered glyph plus a delta.
     *
     * @param list<int> $glyphs
     */
    public static function single1(array $glyphs, int $delta): string
    {
        return pack('nnn', 1, 6, $delta & 0xFFFF) . self::coverage($glyphs);
    }

    /**
     * Single substitution, format 2: glyph => substitute.
     *
     * @param array<int, int> $map
     */
    public static function single2(array $map): string
    {
        ksort($map);

        return (
            pack('nnn', 2, 6 + (2 * count($map)), count($map))
                . self::shorts(array_values($map))
                . self::coverage(array_keys($map))
        );
    }

    /** @param array<string, list<int>> $features */
    private static function featureList(array $features): string
    {
        $records = '';
        $tables  = '';
        $base    = 2 + (6 * count($features));
        foreach ($features as $tag => $indices) {
            $records .= $tag . pack('n', $base + strlen($tables));
            $tables  .= pack('nn', 0, count($indices)) . self::shorts($indices);
        }

        return pack('n', count($features)) . $records . $tables;
    }

    /** @param list<array{0: int, 1: list<string>, 2?: bool, 3?: int, 4?: int}> $lookups */
    private static function lookupList(array $lookups, int $extensionType): string
    {
        $tables  = '';
        $offsets = [];
        $base    = 2 + (2 * count($lookups));
        foreach ($lookups as $lookup) {
            [$type, $subtables] = $lookup;
            $extension  = $lookup[2] ?? false;
            $offsets[]  = $base + strlen($tables);
            $count      = count($subtables);
            $header     = 6 + (2 * $count);
            $body       = '';
            $subOffsets = [];
            foreach ($subtables as $subtable) {
                $subOffsets[] = $header + strlen($body);
                $body         .= $extension ? pack('nnN', 1, $type, 8) . $subtable : $subtable;
            }
            $flag     = $lookup[3] ?? 0;
            $setIndex = 0 === ($flag & 0x10) ? '' : pack('n', $lookup[4] ?? 0);
            if ('' !== $setIndex) {
                $subOffsets = array_map(static fn(int $offset): int => $offset + 2, $subOffsets);
            }
            $tables .=
                pack('nnn', $extension ? $extensionType : $type, $flag, $count)
                . self::shorts($subOffsets)
                . $setIndex
                . $body;
        }

        return pack('n', count($lookups)) . self::shorts($offsets) . $tables;
    }

    /** @param list<array{0: int, 1: int}> $records */
    private static function records(array $records): string
    {
        return (
            pack('n', count($records))
                . implode('', array_map(
                    /** @param array{0: int, 1: int} $r */
                    static fn(array $r): string => pack('nn', $r[0], $r[1]),
                    $records,
                ))
        );
    }

    /** @param list<array{0: list<int>, 1: list<int>, 2: list<int>, 3: list<array{0: int, 1: int}>}> $rules */
    private static function ruleSet(array $rules): string
    {
        $tables  = '';
        $offsets = [];
        $base    = 2 + (2 * count($rules));
        foreach ($rules as [$backtrack, $input, $lookahead, $records]) {
            $offsets[] = $base + strlen($tables);
            $tables    .= pack('n', count($backtrack))
            . self::shorts($backtrack)
            . pack('n', count($input) + 1)
            . self::shorts($input)
            . pack('n', count($lookahead))
            . self::shorts($lookahead)
            . self::records($records);
        }

        return pack('n', count($rules)) . self::shorts($offsets) . $tables;
    }

    /** @param array<string, list<int>> $features */
    private static function scriptList(string $script, array $features, ?string $required): string
    {
        $indices       = [];
        $requiredIndex = 0xFFFF;
        foreach (array_keys($features) as $index => $tag) {
            if ($tag === $required) {
                $requiredIndex = $index;
                continue;
            }
            $indices[] = $index;
        }

        return (
            pack('n', 1)
                . $script
                . pack('n', 8)
                . pack('nn', 4, 0)
                . pack('nnn', 0, $requiredIndex, count($indices))
                . self::shorts($indices)
        );
    }

    /** @param list<int> $values */
    private static function shorts(array $values): string
    {
        return [] === $values ? '' : pack('n*', ...$values);
    }

    /**
     * A format-1 subtable that lists a coverage and one set per covered glyph, in glyph order.
     *
     * @param list<int>          $glyphs
     * @param array<int, string> $sets
     */
    private static function withSets(int $format, array $glyphs, array $sets): string
    {
        $count      = count($sets);
        $coverageAt = 6 + (2 * $count);
        $coverage   = self::coverage($glyphs);
        $offsets    = [];
        $body       = '';
        foreach ($sets as $set) {
            $offsets[] = $coverageAt + strlen($coverage) + strlen($body);
            $body      .= $set;
        }

        return pack('nnn', $format, $coverageAt, $count) . self::shorts($offsets) . $coverage . $body;
    }
}
