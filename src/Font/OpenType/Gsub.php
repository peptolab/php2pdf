<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use function array_column;
use function array_key_exists;
use function array_map;
use function array_slice;
use function array_splice;
use function count;
use function in_array;
use function max;

/**
 * Glyph substitution from a font's GSUB table for the features of a script: single (type 1),
 * multiple (type 2), alternate (type 3, the first alternate), ligature (type 4), context (type 5),
 * chained context (type 6) and reverse chaining (type 8) lookups, used directly or through
 * extension lookups (type 7). Each lookup's flag decides which glyphs it passes over. The lookups
 * of the requested features apply in lookup list order over a glyph sequence; each entry keeps
 * the code points it stands for, and the ligature it belongs to (an id, and for a mark the
 * component it follows) for mark positioning.
 *
 * @phpstan-type Entry array{0: int, 1: list<int>, 2?: int, 3?: int}
 *
 * @internal
 */
final class Gsub
{
    private const int MAX_NESTING = 8;

    private readonly LayoutTables $tables;

    private readonly ChainRules $rules;

    private int $ligatures = 0;

    public function __construct(BinaryReader $gsub, ?Gdef $gdef = null)
    {
        $this->tables = LayoutTables::gsub($gsub, $gdef);
        $this->rules  = new ChainRules($this->tables);
    }

    /**
     * @param list<Entry> $glyphs
     *
     * @return list<int>
     */
    private static function ids(array $glyphs): array
    {
        $ids = [];
        foreach ($glyphs as $entry) {
            $ids[] = $entry[0];
        }

        return $ids;
    }

    /**
     * @param list<Entry> $glyphs
     *
     * @return array{0: list<Entry>, 1: int}
     */
    private static function replace(array $glyphs, int $i, int $glyph): array
    {
        $entry      = $glyphs[$i] ?? [0, []];
        $entry[0]   = $glyph;
        $glyphs[$i] = $entry;

        return [$glyphs, $i + 1];
    }

    /**
     * The input positions after a nested lookup at one of them changed the sequence's length by
     * $delta: glyphs a ligature took are dropped, glyphs a multiple substitution added follow it,
     * and later positions move by $delta.
     *
     * @param list<int> $positions
     *
     * @return list<int>
     */
    private static function shiftPositions(array $positions, int $at, int $delta): array
    {
        $next  = $at + 1;
        $count = count($positions);
        $start = $positions[$at] ?? 0;
        if ($delta > 0) {
            $added = [];
            for ($k = 1; $k <= $delta; ++$k) {
                $added[] = $start + $k;
            }

            return [
                ...array_slice($positions, offset: 0, length: $next),
                ...$added,
                ...array_map(static fn(int $p): int => $p + $delta, array_slice($positions, $next)),
            ];
        }
        $delta = max($delta, $next - $count);

        return [
            ...array_slice($positions, offset: 0, length: $next),
            ...array_map(static fn(int $p): int => $p + $delta, array_slice($positions, $next - $delta)),
        ];
    }

    /**
     * Applies the lookups of the given features, with the script's required feature.
     *
     * @param list<Entry>  $glyphs glyph id and the code points it stands for
     * @param list<string> $features
     *
     * @return list<Entry>
     */
    public function apply(array $glyphs, array $features, string $script = 'latn'): array
    {
        foreach ($this->tables->lookupsFor($features, $script) as $lookup) {
            $glyphs = $this->applyLookup($lookup, $glyphs);
        }

        return $glyphs;
    }

    /** @param list<string> $features */
    public function has(array $features, string $script = 'latn'): bool
    {
        return [] !== $this->tables->lookupsFor($features, $script);
    }

    /**
     * @param list<Entry> $glyphs
     *
     * @return array{0: list<Entry>, 1: int}|null
     */
    private function alternate(int $offset, array $glyphs, int $i): ?array
    {
        $r     = $this->tables->reader;
        $index = $this->tables->coverage($offset + $r->u16($offset + 2))[$glyphs[$i][0] ?? -1] ?? null;
        if (null === $index || 1 !== $r->u16($offset)) {
            return null;
        }
        $set = $offset + $r->u16($offset + 6 + (2 * $index));
        if (0 === $r->u16($set)) {
            return null;
        }

        return self::replace($glyphs, $i, $r->u16($set + 2));
    }

    /**
     * Applies a lookup at one position: the first subtable that matches there. Returns the new
     * sequence and the position to continue from, or null when nothing matched.
     *
     * @param list<Entry> $glyphs
     *
     * @return array{0: list<Entry>, 1: int}|null
     */
    private function applyAt(int $lookup, array $glyphs, int $i, int $depth): ?array
    {
        $glyph  = $glyphs[$i][0] ?? null;
        $filter = $this->tables->filter($lookup);
        if (null === $glyph || $depth > self::MAX_NESTING || $filter->ignores($glyph)) {
            return null;
        }
        foreach ($this->tables->subtablesAt($lookup, $glyph) as [$type, $offset]) {
            $result = match ($type) {
                1       => $this->single($offset, $glyphs, $i),
                2       => $this->multiple($offset, $glyphs, $i),
                3       => $this->alternate($offset, $glyphs, $i),
                4       => $this->ligature($offset, $glyphs, $i, $filter),
                5, 6    => $this->context($type, $offset, $glyphs, $i, $depth, $filter),
                8       => $this->reverse($offset, $glyphs, $i, $filter),
                default => null,
            };
            if (null !== $result) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Applies one lookup at every position of the sequence: forwards, or backwards for reverse
     * chaining.
     *
     * @param list<Entry> $glyphs
     *
     * @return list<Entry>
     */
    private function applyLookup(int $lookup, array $glyphs): array
    {
        $starts = $this->tables->startGlyphs($lookup);
        if ([] === $starts) {
            return $glyphs;
        }
        if (in_array(8, array_column($this->tables->subtables($lookup), column_key: 0), strict: true)) {
            for ($i = count($glyphs) - 1; $i >= 0; --$i) {
                if (! array_key_exists($glyphs[$i][0] ?? -1, $starts)) {
                    continue;
                }
                $glyphs = $this->applyAt($lookup, $glyphs, $i, depth: 0)[0] ?? $glyphs;
            }

            return $glyphs;
        }
        $i = 0;
        while ($i < count($glyphs)) {
            if (! array_key_exists($glyphs[$i][0] ?? -1, $starts)) {
                ++$i;
                continue;
            }
            $result = $this->applyAt($lookup, $glyphs, $i, depth: 0);
            if (null === $result) {
                ++$i;
                continue;
            }
            [$glyphs, $i] = $result;
        }

        return $glyphs;
    }

    /**
     * Context and chained context substitution: when the rule matches at $i, the nested lookups
     * apply at their input positions, which follow any change in the sequence's length.
     *
     * @param list<Entry> $glyphs
     *
     * @return array{0: list<Entry>, 1: int}|null
     */
    private function context(int $type, int $offset, array $glyphs, int $i, int $depth, GlyphFilter $filter): ?array
    {
        $match = $this->rules->match($type, $offset, self::ids($glyphs), $i, $filter);
        if (null === $match) {
            return null;
        }
        [$positions, $records] = $match;
        $end = ($positions[count($positions) - 1] ?? $i) + 1;
        foreach ($records as [$sequenceIndex, $lookupIndex]) {
            $position = $positions[$sequenceIndex] ?? null;
            if (null === $position) {
                continue;
            }
            $before = count($glyphs);
            $result = $this->applyAt($lookupIndex, $glyphs, $position, $depth + 1);
            if (null === $result) {
                continue;
            }
            $glyphs = $result[0];
            $delta  = count($glyphs) - $before;
            if (0 === $delta) {
                continue;
            }
            $end       += $delta;
            $positions = self::shiftPositions($positions, $sequenceIndex, $delta);
        }

        return [$glyphs, max($i + 1, $end)];
    }

    /**
     * Ligature substitution: the components follow the first glyph, passing over glyphs the
     * lookup ignores, which stay after the ligature marked with the component they followed.
     *
     * @param list<Entry> $glyphs
     *
     * @return array{0: list<Entry>, 1: int}|null
     */
    private function ligature(int $offset, array $glyphs, int $i, GlyphFilter $filter): ?array
    {
        $ids = self::ids($glyphs);
        foreach ($this->tables->ligatureSet($offset)[$ids[$i] ?? -1] ?? [] as [$components, $ligature]) {
            $positions = [$i];
            $p         = $i;
            foreach ($components as $component) {
                $p = ChainRules::next($ids, $p, $filter);
                if (null === $p || ($ids[$p] ?? -1) !== $component) {
                    continue 2;
                }
                $positions[] = $p;
            }
            $id         = ++$this->ligatures;
            $codePoints = [];
            $kept       = [];
            $component  = 0;
            foreach (array_slice($glyphs, $i, $p - $i + 1, preserve_keys: true) as $position => $entry) {
                if (in_array($position, $positions, strict: true)) {
                    ++$component;
                    foreach ($entry[1] as $point) {
                        $codePoints[] = $point;
                    }
                    continue;
                }
                $kept[] = [$entry[0], $entry[1], $id, $component];
            }
            array_splice($glyphs, $i, $p - $i + 1, [[$ligature, $codePoints, $id, 0], ...$kept]);

            return [$glyphs, $i + 1];
        }

        return null;
    }

    /**
     * Multiple substitution: one glyph becomes several; the first stands for its characters.
     *
     * @param list<Entry> $glyphs
     *
     * @return array{0: list<Entry>, 1: int}|null
     */
    private function multiple(int $offset, array $glyphs, int $i): ?array
    {
        $r     = $this->tables->reader;
        $entry = $glyphs[$i] ?? null;
        $index = null === $entry ? null : $this->tables->coverage($offset + $r->u16($offset + 2))[$entry[0]] ?? null;
        if (null === $entry || null === $index || 1 !== $r->u16($offset) || $index >= $r->u16($offset + 4)) {
            return null;
        }
        $sequence    = $offset + $r->u16($offset + 6 + (2 * $index));
        $replacement = [];
        foreach ($r->u16List($sequence + 2, $r->u16($sequence)) as $k => $glyph) {
            $replacement[] = [$glyph, 0 === $k ? $entry[1] : [], $entry[2] ?? 0, $entry[3] ?? 0];
        }
        array_splice($glyphs, $i, length: 1, replacement: $replacement);

        return [$glyphs, $i + count($replacement)];
    }

    /**
     * Reverse chaining single substitution, applied from the end of the sequence: a glyph whose
     * backtrack and lookahead match is replaced.
     *
     * @param list<Entry> $glyphs
     *
     * @return array{0: list<Entry>, 1: int}|null
     */
    private function reverse(int $offset, array $glyphs, int $i, GlyphFilter $filter): ?array
    {
        $r     = $this->tables->reader;
        $ids   = self::ids($glyphs);
        $index = $this->tables->coverage($offset + $r->u16($offset + 2))[$ids[$i] ?? -1] ?? null;
        if (null === $index || 1 !== $r->u16($offset)) {
            return null;
        }
        $at        = $offset + 4;
        $backtrack = $r->u16List($at + 2, $r->u16($at));
        $at        += 2 + (2 * count($backtrack));
        $lookahead = $r->u16List($at + 2, $r->u16($at));
        $at        += 2 + (2 * count($lookahead));
        $p         = $i;
        foreach ($backtrack as $coverage) {
            $p = ChainRules::previous($ids, $p, $filter);
            if (null === $p || ! array_key_exists($ids[$p] ?? -1, $this->tables->coverage($offset + $coverage))) {
                return null;
            }
        }
        $p = $i;
        foreach ($lookahead as $coverage) {
            $p = ChainRules::next($ids, $p, $filter);
            if (null === $p || ! array_key_exists($ids[$p] ?? -1, $this->tables->coverage($offset + $coverage))) {
                return null;
            }
        }
        if ($index >= $r->u16($at)) {
            return null;
        }

        return self::replace($glyphs, $i, $r->u16($at + 2 + (2 * $index)));
    }

    /**
     * @param list<Entry> $glyphs
     *
     * @return array{0: list<Entry>, 1: int}|null
     */
    private function single(int $offset, array $glyphs, int $i): ?array
    {
        $r     = $this->tables->reader;
        $glyph = $glyphs[$i][0] ?? -1;
        $index = $this->tables->coverage($offset + $r->u16($offset + 2))[$glyph] ?? null;
        if (null === $index) {
            return null;
        }
        $format = $r->u16($offset);
        if (1 === $format) {
            return self::replace($glyphs, $i, ($glyph + $r->i16($offset + 4)) & 0xFFFF);
        }
        if (2 === $format && $index < $r->u16($offset + 4)) {
            return self::replace($glyphs, $i, $r->u16($offset + 6 + (2 * $index)));
        }

        return null;
    }
}
