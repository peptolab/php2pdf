<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use Php2Pdf\Core\BinaryReader;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Font\TextDirection;

use function array_key_exists;
use function count;

/**
 * Glyph positioning from a font's GPOS table for the features of a script: single (type 1) and
 * pair (type 2) adjustment, cursive (type 3), mark-to-base (type 4), mark-to-ligature (type 5)
 * and mark-to-mark (type 6) attachment, context (type 7) and chained context (type 8)
 * positioning, directly or through extension lookups (type 9). After the lookups, marks lose their advance and attached
 * glyphs take their offsets relative to what they attach to, as HarfBuzz does.
 *
 * Positions are in font units: x advance, y advance, x offset and y offset per glyph.
 *
 * @phpstan-type Entry array{0: int, 1: list<int>, 2?: int, 3?: int, 4?: string}
 * @phpstan-type Position array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}
 *
 * @internal
 */
final class Gpos
{
    private const int MAX_NESTING = 8;

    private readonly LayoutTables $tables;

    private readonly ChainRules $rules;

    /**
     * Pair adjustments read so far, by subtable and pair (first glyph << 16 | second): the first
     * and second glyphs' values and whether the second is passed over, or false for no record.
     *
     * @var array<int, array<int, array{0: array{0: int, 1: int, 2: int, 3: int}, 1: array{0: int, 1: int, 2: int, 3: int}, 2: bool}|false>>
     */
    private array $pairs = [];

    private readonly GposAttachment $attachments;

    /** Whether the sequence being positioned runs right to left. */

    public function __construct(
        BinaryReader $gpos,
        private readonly Gdef $gdef,
    ) {
        $this->tables      = LayoutTables::gpos($gpos, $gdef);
        $this->rules       = new ChainRules($this->tables);
        $this->attachments = new GposAttachment($this->tables, $gdef);
    }

    /**
     * @param array{0: int, 1: int, 2: int, 3: int} $value
     */
    private static function adjust(GlyphPositions $positions, int $i, array $value): void
    {
        $p = $positions->get($i);
        $positions->set($i, [
            $p[0] + $value[2],
            $p[1] + $value[3],
            $p[2] + $value[0],
            $p[3] + $value[1],
            $p[4],
            $p[5],
        ]);
    }

    /**
     * Gives one glyph its final offset, after the glyph it attaches to.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $out
     * @param list<Position>                              $positions
     * @param array<int, true>                            $done
     *
     * @param-out list<array{0: int, 1: int, 2: int, 3: int}> $out
     */
    private static function propagateOne(
        array &$out,
        array $positions,
        int $i,
        array &$done,
        TextDirection $direction,
    ): void {
        if (array_key_exists($i, $done)) {
            return;
        }
        $done[$i] = true;
        $chain    = $positions[$i][4] ?? 0;
        $j        = $i + $chain;
        if (0 === $chain || $j < 0 || $j >= count($out)) {
            return;
        }
        self::propagateOne($out, $positions, $j, $done, $direction);
        $p = $out[$i] ?? [0, 0, 0, 0];
        if (GposAttachment::CURSIVE === ($positions[$i][5] ?? 0)) {
            $out[$i] = [$p[0], $p[1], $p[2], $p[3] + ($out[$j][3] ?? 0)];

            return;
        }
        if ($j >= $i) {
            return;
        }
        $x = $p[2] + ($out[$j][2] ?? 0);
        $y = $p[3] + ($out[$j][3] ?? 0);
        for ($k = $j; $k < $i; ++$k) {
            $from = TextDirection::RightToLeft === $direction ? $k + 1 : $k;
            $sign = TextDirection::RightToLeft === $direction ? 1 : -1;
            $x    += $sign * ($out[$from][0] ?? 0);
            $y    += $sign * ($out[$from][1] ?? 0);
        }
        $out[$i] = [$p[0], $p[1], $x, $y];
    }

    private static function valueSize(int $format): int
    {
        $size = 0;
        for ($bit = 0; $bit < 8; ++$bit) {
            $size += 0 === ($format & (1 << $bit)) ? 0 : 2;
        }

        return $size;
    }

    /**
     * @param list<string> $features
     *
     * @throws ParseException
     */
    public function has(array $features, string $script = 'latn', ?string $language = null): bool
    {
        return [] !== $this->tables->lookupsFor($features, $script, $language);
    }

    /**
     * Positions a glyph sequence: each glyph's x and y advance and offset in font units.
     *
     * @param list<Entry>  $glyphs
     * @param list<int>    $advances the glyphs' horizontal advances
     * @param list<string> $features
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     *
     * @throws ParseException
     */
    public function position(
        array $glyphs,
        array $advances,
        array $features,
        string $script = 'latn',
        TextDirection $direction = TextDirection::LeftToRight,
        ?string $language = null,
    ): array {
        $ids     = [];
        $initial = [];
        foreach ($glyphs as $k => $entry) {
            $ids[]     = $entry[0];
            $initial[] = [$advances[$k] ?? 0, 0, 0, 0, 0, 0];
        }
        $positions = new GlyphPositions($initial);
        foreach ($this->tables->lookupsFor($features, $script, $language) as $lookup) {
            $this->applyLookup($lookup, $glyphs, $ids, $positions, $direction);
        }
        $out = [];
        foreach ($positions->all() as $k => [$xAdvance, $yAdvance, $xOffset, $yOffset]) {
            $mark  = $this->gdef->isMark($ids[$k] ?? 0);
            $out[] = [$mark ? 0 : $xAdvance, $mark ? 0 : $yAdvance, $xOffset, $yOffset];
        }

        return $this->propagate($out, $positions->all(), $direction);
    }

    /**
     * Applies a lookup at one position; returns where to continue, or null when nothing matched.
     *
     * @param list<Entry> $glyphs
     * @param list<int>   $ids
     *
     * @throws ParseException
     */
    private function applyAt(
        int $lookup,
        array $glyphs,
        array $ids,
        GlyphPositions $positions,
        int $i,
        int $depth,
        TextDirection $direction,
    ): ?int {
        $filter = $this->tables->filter($lookup);
        if ($depth > self::MAX_NESTING || ! array_key_exists($i, $ids) || $filter->ignores($ids[$i])) {
            return null;
        }
        foreach ($this->tables->subtablesAt($lookup, $ids[$i]) as [$type, $offset]) {
            $result = match ($type) {
                1       => $this->single($offset, $ids, $positions, $i),
                2       => $this->pair($offset, $ids, $positions, $i, $filter),
                3       => $this->attachments->cursive($offset, $ids, $positions, $i, $filter, $direction),
                4       => $this->attachments->markToBase($offset, $glyphs, $ids, $positions, $i, $filter),
                5       => $this->attachments->markToLigature($offset, $glyphs, $ids, $positions, $i, $filter),
                6       => $this->attachments->markToMark($offset, $glyphs, $ids, $positions, $i, $filter),
                7, 8    => $this->context($type, $offset, [$glyphs, $ids], $positions, $i, $depth, $filter, $direction),
                default => null,
            };
            if (null !== $result) {
                return $result;
            }
        }

        return null;
    }

    /**
     * @param list<Entry> $glyphs
     * @param list<int>   $ids
     *
     * @throws ParseException
     */
    private function applyLookup(
        int $lookup,
        array $glyphs,
        array $ids,
        GlyphPositions $positions,
        TextDirection $direction,
    ): void {
        $starts = $this->tables->startGlyphs($lookup);
        $i      = 0;
        $count  = count($ids);
        while ($i < $count) {
            if (! array_key_exists($ids[$i] ?? -1, $starts)) {
                ++$i;
                continue;
            }
            $i = $this->applyAt($lookup, $glyphs, $ids, $positions, $i, 0, $direction) ?? ($i + 1);
        }
    }

    /**
     * The values of a format 2 class pair.
     *
     * @throws ParseException
     */
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
     * @param array{0: list<Entry>, 1: list<int>} $sequence the glyphs, and their ids
     *
     * @throws ParseException
     */
    private function context(
        int $type,
        int $offset,
        array $sequence,
        GlyphPositions $positions,
        int $i,
        int $depth,
        GlyphFilter $filter,
        TextDirection $direction,
    ): ?int {
        [$glyphs, $ids] = $sequence;
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
            $this->applyAt($lookupIndex, $glyphs, $ids, $positions, $position, $depth + 1, $direction);
        }

        return ($input[count($input) - 1] ?? $i) + 1;
    }

    /**
     * Pair adjustment: the glyph at $i and the next one the lookup does not ignore, by glyph pair
     * (format 1) or class pair (format 2). Continues after the second glyph when it was adjusted.
     *
     * @param list<int> $ids
     *
     * @throws ParseException
     */
    private function pair(int $offset, array $ids, GlyphPositions $positions, int $i, GlyphFilter $filter): ?int
    {
        $r     = $this->tables->reader;
        $index = $this->tables->coverage($offset + $r->u16($offset + 2))[$ids[$i] ?? -1] ?? null;
        $j     = null === $index ? null : ChainRules::next($ids, $i, $filter);
        if (null === $index || null === $j) {
            return null;
        }
        $first  = $ids[$i] ?? -1;
        $second = $ids[$j] ?? -1;
        $key    = ($first << 16) | $second;
        $pair   = $this->pairs[$offset][$key] ?? null;
        if (null === $pair) {
            $pair                       = $this->readPair($offset, $index, $first, $second);
            $this->pairs[$offset][$key] = $pair;
        }
        if (false === $pair) {
            return null;
        }
        self::adjust($positions, $i, $pair[0]);
        self::adjust($positions, $j, $pair[1]);

        return $pair[2] ? $j : $j + 1;
    }

    /**
     * The values of a format 1 pair set for the second glyph, or null when the pair is not listed.
     *
     * @throws ParseException
     */
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
    private function propagate(array $out, array $positions, TextDirection $direction): array
    {
        $done = [];
        foreach ($out as $i => $_) {
            self::propagateOne($out, $positions, $i, $done, $direction);
        }

        return $out;
    }

    /**
     * A pair's adjustments from its subtable: the first and second glyphs' values and whether the
     * second is passed over (it has no value of its own), or false when the subtable has no record
     * for the pair.
     *
     * @return array{0: array{0: int, 1: int, 2: int, 3: int}, 1: array{0: int, 1: int, 2: int, 3: int}, 2: bool}|false
     *
     * @throws ParseException
     */
    private function readPair(int $offset, int $index, int $first, int $second): array|false
    {
        $r       = $this->tables->reader;
        $format1 = $r->u16($offset + 4);
        $format2 = $r->u16($offset + 6);
        $size1   = self::valueSize($format1);
        $record  = match ($r->u16($offset)) {
            1       => $this->pairRecord($offset, $index, $second, $size1 + self::valueSize($format2)),
            2       => $this->classRecord($offset, $first, $second, $size1 + self::valueSize($format2)),
            default => null,
        };
        if (null === $record) {
            return false;
        }

        return [$this->value($record, $format1), $this->value($record + $size1, $format2), 0 === $format2];
    }

    /**
     * Single adjustment: one value for every covered glyph (format 1) or one each (format 2).
     *
     * @param list<int> $ids
     *
     * @throws ParseException
     */
    private function single(int $offset, array $ids, GlyphPositions $positions, int $i): ?int
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

        self::adjust($positions, $i, $this->value($at, $format));

        return $i + 1;
    }

    /**
     * A value record's x placement, y placement, x advance and y advance; device and variation
     * tables are not read.
     *
     * @return array{0: int, 1: int, 2: int, 3: int}
     *
     * @throws ParseException
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
