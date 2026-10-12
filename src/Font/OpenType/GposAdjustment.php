<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use Php2Pdf\Exception\ParseException;

use function array_key_exists;
use function count;

/**
 * GPOS adjustment lookups: single adjustment (type 1) and pair adjustment (type 2) by glyph pair
 * or class pair. Pairs read are kept by subtable, and for a lookup made only of pair adjustments
 * what it does at two adjacent glyphs is remembered by the pair, so Gpos can apply it without
 * walking the subtables again.
 *
 * @internal
 */
final class GposAdjustment
{
    /** The most glyph pairs a lookup's pair memo keeps. */
    private const int PAIR_MEMO_LIMIT = 32_768;

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

    public function __construct(
        private readonly LayoutTables $tables,
    ) {}

    private static function valueSize(int $format): int
    {
        $size = 0;
        for ($bit = 0; $bit < 8; ++$bit) {
            $size += 0 === ($format & (1 << $bit)) ? 0 : 2;
        }

        return $size;
    }

    /**
     * Pair adjustment: the glyph at $i and the next one the lookup does not ignore, by glyph pair
     * (format 1) or class pair (format 2). Continues after the second glyph when it was adjusted.
     *
     * @param list<int> $ids
     *
     * @throws ParseException
     */
    public function pair(int $offset, array $ids, GlyphPositions $positions, int $i, GlyphFilter $filter): ?int
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
     * Whether a lookup is made only of pair adjustment subtables, so remembered() can stand for it.
     *
     * @throws ParseException
     */
    public function pairOnly(int $lookup): bool
    {
        if (! array_key_exists($lookup, $this->pairMemo)) {
            $this->pairMemo[$lookup] = $this->onlyPairs($lookup) ? [] : null;
        }

        return null !== $this->pairMemo[$lookup];
    }

    /**
     * What a lookup of pair adjustments only does at two adjacent glyphs, as pairAt() finds it,
     * remembered by the pair. Text of many different characters (a long-running worker's) would
     * grow the memo without end; it is only a cache, so when full it starts again.
     *
     * @return array{0: array{0: int, 1: int, 2: int, 3: int}|null, 1: array{0: int, 1: int, 2: int, 3: int}|null, 2: int}|bool
     *
     * @throws ParseException
     */
    public function remembered(int $lookup, int $first, int $second): array|bool
    {
        $key  = ($first << 16) | $second;
        $memo = $this->pairMemo[$lookup] ?? [];
        $pair = $memo[$key] ?? null;
        if (null !== $pair) {
            return $pair;
        }
        if (count($memo) >= self::PAIR_MEMO_LIMIT) {
            $this->pairMemo[$lookup] = [];
        }
        $pair                          = $this->pairAt($lookup, $first, $second);
        $this->pairMemo[$lookup][$key] = $pair;

        return $pair;
    }

    /**
     * Single adjustment: one value for every covered glyph (format 1) or one each (format 2).
     *
     * @param list<int> $ids
     *
     * @throws ParseException
     */
    public function single(int $offset, array $ids, GlyphPositions $positions, int $i): ?int
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
     * Whether a lookup has subtables and all of them are pair adjustments.
     *
     * @throws ParseException
     */
    private function onlyPairs(int $lookup): bool
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
            // subtablesAt() gives only the subtables whose coverage holds the glyph
            $index = $this->tables->coverage($offset + $r->u16($offset + 2))[$first] ?? 0;
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
