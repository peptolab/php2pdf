<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use function array_key_exists;
use function array_keys;
use function count;
use function in_array;
use function ksort;
use function max;
use function usort;

/**
 * The parts of a GSUB table substitution reads, parsed on first use and kept: the lookups of each
 * feature in the Latin script's default language system (the default script's when the font has
 * no Latin entry), each lookup's subtables through extensions, coverage tables, class definitions
 * and ligature sets.
 *
 * @internal
 */
final class GsubTables
{
    /** @var array<string, list<int>> feature tag => lookup indices */
    private array $features = [];

    /** @var array<int, list<array{0: int, 1: int}>> lookup index => (type, subtable offset) */
    private array $subtables = [];

    /** @var array<int, array<int, int>> lookup index => glyphs a match can start at (as keys) */
    private array $starts = [];

    /** @var array<int, array<int, int>> coverage offset => glyph => index */
    private array $coverages = [];

    /** @var array<int, array<int, int>> class definition offset => glyph => class */
    private array $classDefs = [];

    /** @var array<int, array<int, list<array{0: list<int>, 1: int}>>> ligature subtable offset => first glyph => ligatures, longest first */
    private array $ligatureSets = [];

    public function __construct(
        public readonly BinaryReader $reader,
    ) {
        $this->readFeatures();
    }

    /** @return array<int, int> glyph => class */
    public function classDef(int $offset): array
    {
        if (array_key_exists($offset, $this->classDefs)) {
            return $this->classDefs[$offset];
        }
        $r   = $this->reader;
        $map = [];
        if (1 === $r->u16($offset)) {
            $start = $r->u16($offset + 2);
            foreach ($r->u16List($offset + 6, $r->u16($offset + 4)) as $k => $class) {
                $map[$start + $k] = $class;
            }
        }
        if (2 === $r->u16($offset)) {
            $count = $r->u16($offset + 2);
            for ($k = 0; $k < $count; ++$k) {
                $record = $offset + 4 + (6 * $k);
                $class  = $r->u16($record + 4);
                for ($glyph = $r->u16($record); $glyph <= $r->u16($record + 2); ++$glyph) {
                    $map[$glyph] = $class;
                }
            }
        }

        return $this->classDefs[$offset] = $map;
    }

    /** @return array<int, int> glyph => coverage index */
    public function coverage(int $offset): array
    {
        if (array_key_exists($offset, $this->coverages)) {
            return $this->coverages[$offset];
        }
        $r     = $this->reader;
        $count = $r->u16($offset + 2);
        $map   = [];
        if (1 === $r->u16($offset)) {
            foreach ($r->u16List($offset + 4, $count) as $index => $glyph) {
                $map[$glyph] = $index;
            }
        }
        if (2 === $r->u16($offset)) {
            for ($k = 0; $k < $count; ++$k) {
                $record = $offset + 4 + (6 * $k);
                $base   = $r->u16($record + 4);
                $start  = $r->u16($record);
                for ($glyph = $start; $glyph <= $r->u16($record + 2); ++$glyph) {
                    $map[$glyph] = $base + ($glyph - $start);
                }
            }
        }

        return $this->coverages[$offset] = $map;
    }

    /** @return array<int, list<array{0: list<int>, 1: int}>> */
    public function ligatureSet(int $offset): array
    {
        if (array_key_exists($offset, $this->ligatureSets)) {
            return $this->ligatureSets[$offset];
        }
        $r   = $this->reader;
        $map = [];
        if (1 === $r->u16($offset)) {
            foreach ($this->coverage($offset + $r->u16($offset + 2)) as $first => $index) {
                $set = $offset + $r->u16($offset + 6 + (2 * $index));
                foreach ($r->u16List($set + 2, $r->u16($set)) as $ligatureOffset) {
                    $ligature      = $set + $ligatureOffset;
                    $map[$first][] = [
                        $r->u16List($ligature + 4, max(0, $r->u16($ligature + 2) - 1)),
                        $r->u16($ligature),
                    ];
                }
            }
        }
        foreach ($map as $first => $ligatures) {
            usort(
                $ligatures,
                /**
                 * @param array{0: list<int>, 1: int} $a
                 * @param array{0: list<int>, 1: int} $b
                 */
                static fn(array $a, array $b): int => count($b[0]) <=> count($a[0]),
            );
            $map[$first] = $ligatures;
        }

        return $this->ligatureSets[$offset] = $map;
    }

    /**
     * Lookup indices of the features, in lookup list order.
     *
     * @param list<string> $features
     *
     * @return list<int>
     */
    public function lookupsFor(array $features): array
    {
        $indices = [];
        foreach ($features as $feature) {
            foreach ($this->features[$feature] ?? [] as $index) {
                $indices[$index] = true;
            }
        }
        ksort($indices);

        return array_keys($indices);
    }

    /**
     * The glyphs a lookup's subtables can match at, as keys: their coverage, or for chained
     * context by coverage (format 3) the first input position's coverage.
     *
     * @return array<int, int>
     */
    public function startGlyphs(int $lookup): array
    {
        if (array_key_exists($lookup, $this->starts)) {
            return $this->starts[$lookup];
        }
        $r      = $this->reader;
        $starts = [];
        foreach ($this->subtables($lookup) as [$type, $offset]) {
            $coverage = $offset + $r->u16($offset + 2);
            if (6 === $type && 3 === $r->u16($offset)) {
                $backtrack = $r->u16($offset + 2);
                $input     = $offset + 4 + (2 * $backtrack);
                if (0 === $r->u16($input)) {
                    continue;
                }
                $coverage = $offset + $r->u16($input + 2);
            }
            $starts += $this->coverage($coverage);
        }

        return $this->starts[$lookup] = $starts;
    }

    /** @return list<array{0: int, 1: int}> sequence index and lookup index */
    public function substRecords(int $at): array
    {
        $r       = $this->reader;
        $records = [];
        for ($k = 0, $count = $r->u16($at); $k < $count; ++$k) {
            $records[] = [$r->u16($at + 2 + (4 * $k)), $r->u16($at + 4 + (4 * $k))];
        }

        return $records;
    }

    /** @return list<array{0: int, 1: int}> */
    public function subtables(int $lookup): array
    {
        if (array_key_exists($lookup, $this->subtables)) {
            return $this->subtables[$lookup];
        }
        $r          = $this->reader;
        $lookupList = $r->u16(8);
        $subtables  = [];
        if ($lookup < $r->u16($lookupList)) {
            $offset = $lookupList + $r->u16($lookupList + 2 + (2 * $lookup));
            $type   = $r->u16($offset);
            foreach ($r->u16List($offset + 6, $r->u16($offset + 4)) as $subtableOffset) {
                $subtable = $offset + $subtableOffset;
                $realType = $type;
                if (7 === $type) {
                    $realType = $r->u16($subtable + 2);
                    $subtable += $r->u32($subtable + 4);
                }
                if (in_array($realType, [1, 3, 4, 6], strict: true)) {
                    $subtables[] = [$realType, $subtable];
                }
            }
        }

        return $this->subtables[$lookup] = $subtables;
    }

    /** Reads the features of the Latin script's default language system, else the default script's. */
    private function readFeatures(): void
    {
        $r          = $this->reader;
        $scriptList = $r->u16(4);
        $scripts    = [];
        for ($k = 0, $count = $r->u16($scriptList); $k < $count; ++$k) {
            $record                    = $scriptList + 2 + (6 * $k);
            $scripts[$r->tag($record)] = $scriptList + $r->u16($record + 4);
        }
        $script = $scripts['latn'] ?? $scripts['DFLT'] ?? null;
        if (null === $script || 0 === $r->u16($script)) {
            return;
        }
        $lang        = $script + $r->u16($script);
        $featureList = $r->u16(6);
        foreach ($r->u16List($lang + 6, $r->u16($lang + 4)) as $featureIndex) {
            $record  = $featureList + 2 + (6 * $featureIndex);
            $feature = $featureList + $r->u16($record + 4);
            foreach ($r->u16List($feature + 4, $r->u16($feature + 2)) as $lookup) {
                $this->features[$r->tag($record)][] = $lookup;
            }
        }
    }
}
