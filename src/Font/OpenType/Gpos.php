<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use Php2Pdf\Core\BinaryReader;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Font\TextDirection;

use function array_flip;
use function array_intersect_key;
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

    /** The most glyph pairs a lookup's pair memo keeps. */
    private const int PAIR_MEMO_LIMIT = 32768;

    private readonly LayoutTables $tables;

    private readonly ChainRules $rules;

    /**
     * Pair adjustments read so far, by subtable and pair (first glyph << 16 | second): the first
     * and second glyphs' values and whether the second is passed over, or false for no record.
     *
     * @var array<int, array<int, array{0: array{0: int, 1: int, 2: int, 3: int}, 1: array{0: int, 1: int, 2: int, 3: int}, 2: bool}|false>>
     */
    private array $pairs = [];

    /**
     * For each lookup made only of pair adjustment subtables, what it does at two adjacent glyphs
     * by pair (first glyph << 16 | second): the first and second glyphs' values (each null when it
     * changes nothing, as a class pair's record of zeros does) and how far to step on; false when
     * no subtable has the pair, so the lookup moves on by one; true when the lookup passes over one
     * of the two glyphs, so the general path applies. Null for a lookup with other subtables.
     *
     * @var array<int, array<int, array{0: array{0: int, 1: int, 2: int, 3: int}|null, 1: array{0: int, 1: int, 2: int, 3: int}|null, 2: int}|bool>|null>
     */
    private array $pairMemo = [];

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
     * Gives one glyph its final offset, after the glyph it attaches to.
     *
     * @param list<array{0: int, 1: int, 2: int, 3: int}> $out
     * @param array<int, Position>                        $positions
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
        // Marks attach to a glyph before them, so $j < $i here.
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
     * Positions a glyph sequence: each glyph's x and y advance and offset in font units, by its
     * index. A glyph that is not listed keeps its advance and has no offset; every glyph is listed
     * when one attaches to another.
     *
     * @param list<Entry>  $glyphs
     * @param list<int>    $advances the glyphs' horizontal advances
     * @param list<string> $features
     *
     * @return array<int, array{0: int, 1: int, 2: int, 3: int}>
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
        $ids = [];
        foreach ($glyphs as $entry) {
            $ids[] = $entry[0];
        }
        $positions = GlyphPositions::natural($advances, count($ids));
        $present   = array_flip($ids);
        foreach ($this->tables->lookupsFor($features, $script, $language) as $lookup) {
            // A lookup none of whose starting glyphs is in the sequence cannot apply.
            if ([] === array_intersect_key($present, $this->tables->startGlyphs($lookup))) {
                continue;
            }
            $this->applyLookup($lookup, $glyphs, $ids, $positions, $direction);
        }
        $given    = $positions->given();
        $attached = false;
        foreach ($given as [, , , , $chain]) {
            if (0 !== $chain) {
                $attached = true;
                break;
            }
        }
        // Marks lose their advance, so they are listed whether positioned or not.
        $classes = $this->gdef->classMap();
        $out     = [];
        foreach ($ids as $k => $id) {
            $mark = Gdef::MARK === ($classes[$id] ?? 0);
            $p    = $given[$k] ?? null;
            if (null === $p) {
                if ($mark) {
                    $out[$k] = [0, 0, 0, 0];
                } elseif ($attached) {
                    $out[$k] = [$advances[$k] ?? 0, 0, 0, 0];
                }
                continue;
            }
            $out[$k] = $mark ? [0, 0, $p[2], $p[3]] : [$p[0], $p[1], $p[2], $p[3]];
        }

        // Propagation moves only glyphs attached to another (a non-zero chain); with none, it
        // would leave every offset as it is.
        return $attached ? $this->propagate($out, $given, $direction) : $out;
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
        // Every value of $starts is a non-empty list, so a null lookup means the glyph starts no
        // rule, as array_key_exists() would say.
        $starts = $this->tables->startGlyphs($lookup);
        $i      = 0;
        $count  = count($ids);
        if (! array_key_exists($lookup, $this->pairMemo)) {
            $this->pairMemo[$lookup] = $this->pairOnly($lookup) ? [] : null;
        }
        if (null === $this->pairMemo[$lookup]) {
            while ($i < $count) {
                if (null === ($starts[$ids[$i] ?? -1] ?? null)) {
                    ++$i;
                    continue;
                }
                $i = $this->applyAt($lookup, $glyphs, $ids, $positions, $i, 0, $direction) ?? ($i + 1);
            }

            return;
        }
        // A lookup of pair adjustments only does at a glyph what applyAt() would: the first of its
        // subtables that covers the glyph and has a record for it and the glyph after wins, and a
        // second glyph with no value of its own (value format 0) is passed over. That depends only
        // on the two glyph ids when the lookup ignores neither (whether it ignores one depends only
        // on its id), so it is remembered by pair; when it ignores either, the second glyph would
        // be another, so the general path applies. The ids are 0 to 65535, or REMOVED (-1), which
        // is ignored: a key of -1 is a removed second glyph whatever the first. The last glyph has
        // none after it to pair with, so no pair applies there.
        $memo = &$this->pairMemo[$lookup];
        $last = $count - 1;
        while ($i < $last) {
            $first = $ids[$i];
            if (null === ($starts[$first] ?? null)) {
                ++$i;
                continue;
            }
            $key  = ($first << 16) | $ids[$i + 1];
            $pair = $memo[$key] ?? null;
            if (null === $pair) {
                if (count($memo) >= self::PAIR_MEMO_LIMIT) {
                    // Text of many different characters (a long-running worker's) would grow it
                    // without end; it is only a cache, so it starts again.
                    $memo = [];
                }
                $pair = $memo[$key] = $this->pairAt($lookup, $first, $ids[$i + 1]);
            }
            if (false === $pair) {
                ++$i;
                continue;
            }
            if (true === $pair) {
                $i = $this->applyAt($lookup, $glyphs, $ids, $positions, $i, 0, $direction) ?? ($i + 1);
                continue;
            }
            if (null !== $pair[0]) {
                $positions->adjust($i, $pair[0]);
            }
            if (null !== $pair[1]) {
                $positions->adjust($i + 1, $pair[1]);
            }
            $i += $pair[2];
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
        $positions->adjust($i, $pair[0]);
        $positions->adjust($j, $pair[1]);

        return $pair[2] ? $j : $j + 1;
    }

    /**
     * What a lookup of pair adjustments does at two adjacent glyphs, as pair() finds it: the
     * values and step of the first subtable with a record for them, false for none, or true when
     * the lookup ignores either glyph.
     *
     * @return array{0: array{0: int, 1: int, 2: int, 3: int}|null, 1: array{0: int, 1: int, 2: int, 3: int}|null, 2: int}|bool
     *
     * @throws ParseException
     */
    private function pairAt(int $lookup, int $first, int $second): array|bool
    {
        $filter = $this->tables->filter($lookup);
        if ($filter->ignores($first) || $filter->ignores($second)) {
            return true;
        }
        $r = $this->tables->reader;
        foreach ($this->tables->subtablesAt($lookup, $first) as [, $offset]) {
            $index = $this->tables->coverage($offset + $r->u16($offset + 2))[$first] ?? null;
            if (null === $index) {
                continue;
            }
            // Not kept in $this->pairs as well: the lookup's memo keeps what this returns.
            $pair = $this->pairs[$offset][($first << 16) | $second]
                ?? $this->readPair($offset, $index, $first, $second);
            if (false === $pair) {
                continue;
            }

            return [
                [0, 0, 0, 0] === $pair[0] ? null : $pair[0],
                [0, 0, 0, 0] === $pair[1] ? null : $pair[1],
                $pair[2] ? 1 : 2,
            ];
        }

        return false;
    }

    /**
     * Whether a lookup is made only of pair adjustment subtables.
     *
     * @throws ParseException
     */
    private function pairOnly(int $lookup): bool
    {
        $subtables = $this->tables->subtables($lookup);
        foreach ($subtables as [$type]) {
            if (2 !== $type) {
                return false;
            }
        }

        return [] !== $subtables;
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
     * @param array<int, Position>                        $positions the positions given
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
        $r = $this->tables->reader;
        // subtablesAt() gives only the subtables whose coverage holds the glyph
        $index  = $this->tables->coverage($offset + $r->u16($offset + 2))[$ids[$i] ?? -1] ?? 0;
        $format = $r->u16($offset + 4);
        $at     = match ($r->u16($offset)) {
            1       => $offset + 6,
            2       => $index < $r->u16($offset + 6) ? $offset + 8 + ($index * self::valueSize($format)) : null,
            default => null,
        };
        if (null === $at) {
            return null;
        }

        $positions->adjust($i, $this->value($at, $format));

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
