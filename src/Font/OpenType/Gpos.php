<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use function array_key_exists;
use function count;
use function min;

/**
 * Glyph positioning from a font's GPOS table for the features of a script: single (type 1) and
 * pair (type 2) adjustment, mark-to-base (type 4), mark-to-ligature (type 5) and mark-to-mark
 * (type 6) attachment, context (type 7) and chained context (type 8) positioning, directly or
 * through extension lookups (type 9). After the lookups, marks lose their advance and attached
 * glyphs take their offsets relative to what they attach to, as HarfBuzz does.
 *
 * Positions are in font units: x advance, y advance, x offset and y offset per glyph.
 *
 * @phpstan-type Entry array{0: int, 1: list<int>, 2?: int, 3?: int}
 * @phpstan-type Position array{0: int, 1: int, 2: int, 3: int, 4: int}
 *
 * @internal
 */
final class Gpos
{
    private const int MAX_NESTING = 8;

    private readonly LayoutTables $tables;

    private readonly ChainRules $rules;

    public function __construct(
        BinaryReader $gpos,
        private readonly Gdef $gdef,
    ) {
        $this->tables = LayoutTables::gpos($gpos, $gdef);
        $this->rules  = new ChainRules($this->tables);
    }

    /**
     * @param list<Position>                   $positions
     * @param array{0: int, 1: int, 2: int, 3: int} $value
     *
     * @return list<Position>
     */
    private static function adjust(array $positions, int $i, array $value): array
    {
        $p = $positions[$i] ?? [0, 0, 0, 0, 0];

        $positions[$i] = [$p[0] + $value[2], $p[1] + $value[3], $p[2] + $value[0], $p[3] + $value[1], $p[4]];

        return $positions;
    }

    /**
     * Attaches the glyph at $i to the one at $base: its offset puts its anchor on the other's.
     *
     * @param list<Position>         $positions
     * @param array{0: int, 1: int} $baseAnchor
     * @param array{0: int, 1: int} $markAnchor
     *
     * @return list<Position>
     */
    private static function attach(array $positions, int $i, int $base, array $baseAnchor, array $markAnchor): array
    {
        $p             = $positions[$i] ?? [0, 0, 0, 0, 0];
        $positions[$i] = [$p[0], $p[1], $baseAnchor[0] - $markAnchor[0], $baseAnchor[1] - $markAnchor[1], $base - $i];

        return $positions;
    }

    /**
     * Whether two marks may attach: both outside ligatures, on the same ligature component, or
     * one of them on a ligature as a whole.
     *
     * @param list<Entry> $glyphs
     */
    private static function sameComponent(array $glyphs, int $i, int $j): bool
    {
        $id1   = $glyphs[$i][2] ?? 0;
        $id2   = $glyphs[$j][2] ?? 0;
        $comp1 = $glyphs[$i][3] ?? 0;
        $comp2 = $glyphs[$j][3] ?? 0;
        if ($id1 === $id2) {
            return 0 === $id1 || $comp1 === $comp2;
        }

        return ($id1 > 0 && 0 === $comp1) || ($id2 > 0 && 0 === $comp2);
    }

    private static function valueSize(int $format): int
    {
        $size = 0;
        for ($bit = 0; $bit < 8; ++$bit) {
            $size += 0 === ($format & (1 << $bit)) ? 0 : 2;
        }

        return $size;
    }

    /** @param list<string> $features */
    public function has(array $features, string $script = 'latn'): bool
    {
        return [] !== $this->tables->lookupsFor($features, $script);
    }

    /**
     * Positions a glyph sequence: each glyph's x and y advance and offset in font units.
     *
     * @param list<Entry>  $glyphs
     * @param list<int>    $advances the glyphs' horizontal advances
     * @param list<string> $features
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    public function position(
        array $glyphs,
        array $advances,
        array $features,
        string $script = 'latn',
        bool $rightToLeft = false,
    ): array {
        $ids       = [];
        $positions = [];
        foreach ($glyphs as $k => $entry) {
            $ids[]       = $entry[0];
            $positions[] = [$advances[$k] ?? 0, 0, 0, 0, 0];
        }
        foreach ($this->tables->lookupsFor($features, $script) as $lookup) {
            $positions = $this->applyLookup($lookup, $glyphs, $ids, $positions);
        }
        $out = [];
        foreach ($positions as $k => [$xAdvance, $yAdvance, $xOffset, $yOffset]) {
            $mark  = $this->gdef->isMark($ids[$k] ?? 0);
            $out[] = [$mark ? 0 : $xAdvance, $mark ? 0 : $yAdvance, $xOffset, $yOffset];
        }

        return $this->propagate($out, $positions, $rightToLeft);
    }

    /**
     * An anchor's coordinates: formats 1 to 3, without contour points or device tables.
     *
     * @return array{0: int, 1: int}
     */
    private function anchor(int $offset): array
    {
        $r = $this->tables->reader;

        return [$r->i16($offset + 2), $r->i16($offset + 4)];
    }

    /**
     * Applies a lookup at one position; returns the new positions and where to continue, or null.
     *
     * @param list<Entry>    $glyphs
     * @param list<int>      $ids
     * @param list<Position> $positions
     *
     * @return array{0: list<Position>, 1: int}|null
     */
    private function applyAt(int $lookup, array $glyphs, array $ids, array $positions, int $i, int $depth): ?array
    {
        $filter = $this->tables->filter($lookup);
        if ($depth > self::MAX_NESTING || ! array_key_exists($i, $ids) || $filter->ignores($ids[$i])) {
            return null;
        }
        foreach ($this->tables->subtablesAt($lookup, $ids[$i]) as [$type, $offset]) {
            $result = match ($type) {
                1       => $this->single($offset, $ids, $positions, $i),
                2       => $this->pair($offset, $ids, $positions, $i, $filter),
                4       => $this->markToBase($offset, $glyphs, $ids, $positions, $i, $filter),
                5       => $this->markToLigature($offset, $glyphs, $ids, $positions, $i, $filter),
                6       => $this->markToMark($offset, $glyphs, $ids, $positions, $i, $filter),
                7, 8    => $this->context($type, $offset, $glyphs, $ids, $positions, $i, $depth, $filter),
                default => null,
            };
            if (null !== $result) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param list<Entry>    $glyphs
     * @param list<int>      $ids
     * @param list<Position> $positions
     *
     * @return list<Position>
     */
    private function applyLookup(int $lookup, array $glyphs, array $ids, array $positions): array
    {
        $starts = $this->tables->startGlyphs($lookup);
        $i      = 0;
        $count  = count($ids);
        while ($i < $count) {
            if (! array_key_exists($ids[$i] ?? -1, $starts)) {
                ++$i;
                continue;
            }
            $result = $this->applyAt($lookup, $glyphs, $ids, $positions, $i, depth: 0);
            if (null === $result) {
                ++$i;
                continue;
            }
            [$positions, $i] = $result;
        }

        return $positions;
    }

    /** The values of a format 2 class pair. */
    private function classRecord(int $offset, int $first, int $second, int $recordSize): ?int
    {
        $r      = $this->tables->reader;
        $class1 = $this->tables->classDef($offset + $r->u16($offset + 8))[$first] ?? 0;
        $class2 = $this->tables->classDef($offset + $r->u16($offset + 10))[$second] ?? 0;
        $count1 = $r->u16($offset + 12);
        $count2 = $r->u16($offset + 14);
        if ($class1 >= $count1 || $class2 >= $count2) {
            return null;
        }

        return $offset + 16 + ((($class1 * $count2) + $class2) * $recordSize);
    }

    /**
     * Context and chained context positioning: the nested lookups apply at their input positions.
     *
     * @param list<Entry>    $glyphs
     * @param list<int>      $ids
     * @param list<Position> $positions
     *
     * @return array{0: list<Position>, 1: int}|null
     */
    private function context(
        int $type,
        int $offset,
        array $glyphs,
        array $ids,
        array $positions,
        int $i,
        int $depth,
        GlyphFilter $filter,
    ): ?array {
        $match = $this->rules->match($type, $offset, $ids, $i, $filter);
        if (null === $match) {
            return null;
        }
        [$input, $records] = $match;
        foreach ($records as [$sequenceIndex, $lookupIndex]) {
            $position = $input[$sequenceIndex] ?? null;
            if (null === $position) {
                continue;
            }
            $positions =
                $this->applyAt($lookupIndex, $glyphs, $ids, $positions, $position, $depth + 1)[0] ?? $positions;
        }

        return [$positions, ($input[count($input) - 1] ?? $i) + 1];
    }

    /**
     * Whether the glyph at $j is a later glyph of a multiple substitution, which marks pass over
     * to attach to its first glyph.
     *
     * @param list<Entry> $glyphs
     */
    private function isLaterComponent(array $glyphs, int $j): bool
    {
        return [] === ($glyphs[$j][1] ?? [0]) && $j > 0 && ! $this->gdef->isMark($glyphs[$j - 1][0] ?? 0);
    }

    /**
     * Mark-to-base attachment: the mark at $i attaches to the base before it, passing over marks.
     *
     * @param list<Entry>    $glyphs
     * @param list<int>      $ids
     * @param list<Position> $positions
     *
     * @return array{0: list<Position>, 1: int}|null
     */
    private function markToBase(
        int $offset,
        array $glyphs,
        array $ids,
        array $positions,
        int $i,
        GlyphFilter $filter,
    ): ?array {
        $r    = $this->tables->reader;
        $mark = $this->tables->coverage($offset + $r->u16($offset + 2))[$ids[$i] ?? -1] ?? null;
        if (null === $mark) {
            return null;
        }
        $j = ChainRules::previous($ids, $i, $filter->marksOnly());
        while (null !== $j && $this->isLaterComponent($glyphs, $j)) {
            $j = ChainRules::previous($ids, $j, $filter->marksOnly());
        }
        $base = null === $j ? null : $this->tables->coverage($offset + $r->u16($offset + 4))[$ids[$j] ?? -1] ?? null;
        if (null === $j || null === $base) {
            return null;
        }
        $classes   = $r->u16($offset + 6);
        $markArray = $offset + $r->u16($offset + 8);
        $baseArray = $offset + $r->u16($offset + 10);
        $record    = $markArray + 2 + (4 * $mark);
        $class     = $r->u16($record);
        $anchor    = $r->u16($baseArray + 2 + (2 * (($base * $classes) + $class)));
        if (0 === $anchor || $class >= $classes) {
            return null;
        }

        return [
            self::attach(
                $positions,
                $i,
                $j,
                $this->anchor($baseArray + $anchor),
                $this->anchor($markArray + $r->u16($record + 2)),
            ),
            $i + 1,
        ];
    }

    /**
     * Mark-to-ligature attachment: the mark attaches to the ligature component it followed, or to
     * the last component.
     *
     * @param list<Entry>    $glyphs
     * @param list<int>      $ids
     * @param list<Position> $positions
     *
     * @return array{0: list<Position>, 1: int}|null
     */
    private function markToLigature(
        int $offset,
        array $glyphs,
        array $ids,
        array $positions,
        int $i,
        GlyphFilter $filter,
    ): ?array {
        $r    = $this->tables->reader;
        $mark = $this->tables->coverage($offset + $r->u16($offset + 2))[$ids[$i] ?? -1] ?? null;
        $j    = null === $mark ? null : ChainRules::previous($ids, $i, $filter->marksOnly());
        $lig  = null === $j ? null : $this->tables->coverage($offset + $r->u16($offset + 4))[$ids[$j] ?? -1] ?? null;
        if (null === $mark || null === $j || null === $lig) {
            return null;
        }
        $classes    = $r->u16($offset + 6);
        $markArray  = $offset + $r->u16($offset + 8);
        $ligArray   = $offset + $r->u16($offset + 10);
        $attach     = $ligArray + $r->u16($ligArray + 2 + (2 * $lig));
        $components = $r->u16($attach);
        if (0 === $components) {
            return null;
        }
        $ligId     = $glyphs[$j][2] ?? 0;
        $markLigId = $glyphs[$i][2] ?? 0;
        $markComp  = $glyphs[$i][3] ?? 0;
        $component = 0 !== $ligId && $ligId === $markLigId && $markComp > 0
            ? min($components, $markComp) - 1
            : $components - 1;
        $record = $markArray + 2 + (4 * $mark);
        $class  = $r->u16($record);
        $anchor = $r->u16($attach + 2 + (2 * (($component * $classes) + $class)));
        if (0 === $anchor || $class >= $classes) {
            return null;
        }

        return [
            self::attach(
                $positions,
                $i,
                $j,
                $this->anchor($attach + $anchor),
                $this->anchor($markArray + $r->u16($record + 2)),
            ),
            $i + 1,
        ];
    }

    /**
     * Mark-to-mark attachment: the mark attaches to the mark before it when both belong to the
     * same ligature component, or neither to a ligature.
     *
     * @param list<Entry>    $glyphs
     * @param list<int>      $ids
     * @param list<Position> $positions
     *
     * @return array{0: list<Position>, 1: int}|null
     */
    private function markToMark(
        int $offset,
        array $glyphs,
        array $ids,
        array $positions,
        int $i,
        GlyphFilter $filter,
    ): ?array {
        $r     = $this->tables->reader;
        $mark1 = $this->tables->coverage($offset + $r->u16($offset + 2))[$ids[$i] ?? -1] ?? null;
        $j     = null === $mark1 ? null : ChainRules::previous($ids, $i, $filter);
        if (
            null === $mark1
            || null === $j
            || ! $this->gdef->isMark($ids[$j] ?? 0)
            || ! self::sameComponent($glyphs, $i, $j)
        ) {
            return null;
        }
        $mark2 = $this->tables->coverage($offset + $r->u16($offset + 4))[$ids[$j] ?? -1] ?? null;
        if (null === $mark2) {
            return null;
        }
        $classes    = $r->u16($offset + 6);
        $mark1Array = $offset + $r->u16($offset + 8);
        $mark2Array = $offset + $r->u16($offset + 10);
        $record     = $mark1Array + 2 + (4 * $mark1);
        $class      = $r->u16($record);
        $anchor     = $r->u16($mark2Array + 2 + (2 * (($mark2 * $classes) + $class)));
        if (0 === $anchor || $class >= $classes) {
            return null;
        }

        return [
            self::attach(
                $positions,
                $i,
                $j,
                $this->anchor($mark2Array + $anchor),
                $this->anchor($mark1Array + $r->u16($record + 2)),
            ),
            $i + 1,
        ];
    }

    /**
     * Pair adjustment: the glyph at $i and the next one the lookup does not ignore, by glyph pair
     * (format 1) or class pair (format 2). Continues after the second glyph when it was adjusted.
     *
     * @param list<int>      $ids
     * @param list<Position> $positions
     *
     * @return array{0: list<Position>, 1: int}|null
     */
    private function pair(int $offset, array $ids, array $positions, int $i, GlyphFilter $filter): ?array
    {
        $r     = $this->tables->reader;
        $index = $this->tables->coverage($offset + $r->u16($offset + 2))[$ids[$i] ?? -1] ?? null;
        $j     = null === $index ? null : ChainRules::next($ids, $i, $filter);
        if (null === $index || null === $j) {
            return null;
        }
        $format1 = $r->u16($offset + 4);
        $format2 = $r->u16($offset + 6);
        $size1   = self::valueSize($format1);
        $record  = match ($r->u16($offset)) {
            1       => $this->pairRecord($offset, $index, $ids[$j] ?? -1, $size1 + self::valueSize($format2)),
            2       => $this->classRecord($offset, $ids[$i] ?? -1, $ids[$j] ?? -1, $size1 + self::valueSize($format2)),
            default => null,
        };
        if (null === $record) {
            return null;
        }
        $positions = self::adjust($positions, $i, $this->value($record, $format1));
        $positions = self::adjust($positions, $j, $this->value($record + $size1, $format2));

        return [$positions, 0 === $format2 ? $j : $j + 1];
    }

    /** The values of a format 1 pair set for the second glyph, or null when the pair is not listed. */
    private function pairRecord(int $offset, int $index, int $second, int $recordSize): ?int
    {
        $r    = $this->tables->reader;
        $set  = $offset + $r->u16($offset + 10 + (2 * $index));
        $low  = 0;
        $high = $r->u16($set) - 1;
        $step = 2 + $recordSize;
        while ($low <= $high) {
            $mid   = ($low + $high) >> 1;
            $at    = $set + 2 + ($mid * $step);
            $glyph = $r->u16($at);
            if ($glyph === $second) {
                return $at + 2;
            }
            if ($glyph < $second) {
                $low = $mid + 1;
                continue;
            }
            $high = $mid - 1;
        }

        return null;
    }

    /**
     * Gives attached glyphs their final offsets: an attached glyph takes the offset of what it
     * attaches to, less the advances from it to the glyph, or for right-to-left text (drawn in
     * reverse) plus the advances after it up to the glyph.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $out
     * @param list<Position>                              $positions
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    private function propagate(array $out, array $positions, bool $rightToLeft): array
    {
        $done = [];
        foreach ($out as $i => $_) {
            $out = $this->propagateOne($out, $positions, $i, $done, $rightToLeft);
        }

        return $out;
    }

    /**
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $out
     * @param list<Position>                              $positions
     * @param array<int, true>                            $done
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    private function propagateOne(array $out, array $positions, int $i, array &$done, bool $rightToLeft): array
    {
        if (array_key_exists($i, $done)) {
            return $out;
        }
        $done[$i] = true;
        $chain    = $positions[$i][4] ?? 0;
        $j        = $i + $chain;
        if (0 === $chain || $j < 0 || $j >= $i) {
            return $out;
        }
        $out = $this->propagateOne($out, $positions, $j, $done, $rightToLeft);
        $p   = $out[$i] ?? [0, 0, 0, 0];
        $x   = $p[2] + ($out[$j][2] ?? 0);
        $y   = $p[3] + ($out[$j][3] ?? 0);
        for ($k = $j; $k < $i; ++$k) {
            $from = $rightToLeft ? $k + 1 : $k;
            $sign = $rightToLeft ? 1 : -1;
            $x    += $sign * ($out[$from][0] ?? 0);
            $y    += $sign * ($out[$from][1] ?? 0);
        }
        $out[$i] = [$p[0], $p[1], $x, $y];

        return $out;
    }

    /**
     * Single adjustment: one value for every covered glyph (format 1) or one each (format 2).
     *
     * @param list<int>      $ids
     * @param list<Position> $positions
     *
     * @return array{0: list<Position>, 1: int}|null
     */
    private function single(int $offset, array $ids, array $positions, int $i): ?array
    {
        $r      = $this->tables->reader;
        $index  = $this->tables->coverage($offset + $r->u16($offset + 2))[$ids[$i] ?? -1] ?? null;
        $format = $r->u16($offset + 4);
        if (null === $index) {
            return null;
        }
        $at = match ($r->u16($offset)) {
            1       => $offset + 6,
            2       => $index < $r->u16($offset + 6) ? $offset + 8 + ($index * self::valueSize($format)) : null,
            default => null,
        };
        if (null === $at) {
            return null;
        }

        return [self::adjust($positions, $i, $this->value($at, $format)), $i + 1];
    }

    /**
     * A value record's x placement, y placement, x advance and y advance; device and variation
     * tables are not read.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     */
    private function value(int $at, int $format): array
    {
        $r      = $this->tables->reader;
        $values = [0, 0, 0, 0];
        foreach ([0, 1, 2, 3] as $bit) {
            if (0 === ($format & (1 << $bit))) {
                continue;
            }
            $values[$bit] = $r->i16($at);
            $at           += 2;
        }

        return [$values[0], $values[1], $values[2], $values[3]];
    }
}
