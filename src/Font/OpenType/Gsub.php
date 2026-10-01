<?php

declare(strict_types=1);

namespace PhpPdf\Font\OpenType;

use function array_column;
use function array_key_exists;
use function array_slice;
use function array_splice;
use function count;
use function max;

/**
 * Glyph substitution from a font's GSUB table for features of the Latin script (the default
 * script when the font has no Latin entry): single (type 1), alternate (type 3, the first
 * alternate), ligature (type 4) and chained context (type 6, all three formats) lookups, used
 * directly or through extension lookups (type 7). The lookups of the requested features apply in
 * lookup list order over a glyph sequence; each entry keeps the code points it stands for.
 *
 * @internal
 */
final class Gsub
{
    private const int MAX_NESTING = 8;

    private readonly GsubTables $tables;

    private readonly ChainRules $rules;

    public function __construct(BinaryReader $gsub)
    {
        $this->tables = new GsubTables($gsub);
        $this->rules  = new ChainRules($this->tables);
    }

    /**
     * @param list<array{0: int, 1: list<int>}> $glyphs
     *
     * @return array{0: list<array{0: int, 1: list<int>}>, 1: int}
     */
    private static function replace(array $glyphs, int $i, int $glyph): array
    {
        $glyphs[$i] = [$glyph, $glyphs[$i][1] ?? []];

        return [$glyphs, $i + 1];
    }

    /**
     * Applies the lookups of the given features.
     *
     * @param list<array{0: int, 1: list<int>}> $glyphs glyph id and the code points it stands for
     * @param list<string>                      $features
     *
     * @return list<array{0: int, 1: list<int>}>
     */
    public function apply(array $glyphs, array $features): array
    {
        foreach ($this->tables->lookupsFor($features) as $lookup) {
            $glyphs = $this->applyLookup($lookup, $glyphs);
        }

        return $glyphs;
    }

    /** @param list<string> $features */
    public function has(array $features): bool
    {
        return [] !== $this->tables->lookupsFor($features);
    }

    /**
     * @param list<array{0: int, 1: list<int>}> $glyphs
     *
     * @return array{0: list<array{0: int, 1: list<int>}>, 1: int}|null
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
     * @param list<array{0: int, 1: list<int>}> $glyphs
     *
     * @return array{0: list<array{0: int, 1: list<int>}>, 1: int}|null
     */
    private function applyAt(int $lookup, array $glyphs, int $i, int $depth): ?array
    {
        $glyph = $glyphs[$i][0] ?? null;
        if (null === $glyph || $depth > self::MAX_NESTING) {
            return null;
        }
        foreach ($this->tables->subtables($lookup) as [$type, $offset]) {
            $result = match ($type) {
                1       => $this->single($offset, $glyphs, $i),
                3       => $this->alternate($offset, $glyphs, $i),
                4       => $this->ligature($offset, $glyphs, $i),
                6       => $this->chained($offset, $glyphs, $i, $depth),
                default => null,
            };
            if (null !== $result) {
                return $result;
            }
        }

        return null;
    }

    /**
     * Applies one lookup at every position of the sequence.
     *
     * @param list<array{0: int, 1: list<int>}> $glyphs
     *
     * @return list<array{0: int, 1: list<int>}>
     */
    private function applyLookup(int $lookup, array $glyphs, int $depth = 0): array
    {
        $starts = $this->tables->startGlyphs($lookup);
        $i      = 0;
        while ($i < count($glyphs)) {
            if (! array_key_exists($glyphs[$i][0] ?? -1, $starts)) {
                ++$i;
                continue;
            }
            $result = $this->applyAt($lookup, $glyphs, $i, $depth);
            if (null === $result) {
                ++$i;
                continue;
            }
            [$glyphs, $next] = $result;
            $i = $next;
        }

        return $glyphs;
    }

    /**
     * Chained context substitution: when the backtrack, input and lookahead sequences match at
     * $i, the nested lookups apply at their positions in the input.
     *
     * @param list<array{0: int, 1: list<int>}> $glyphs
     *
     * @return array{0: list<array{0: int, 1: list<int>}>, 1: int}|null
     */
    private function chained(int $offset, array $glyphs, int $i, int $depth): ?array
    {
        $rule = match ($this->tables->reader->u16($offset)) {
            1       => $this->rules->format1($offset, $glyphs, $i),
            2       => $this->rules->format2($offset, $glyphs, $i),
            3       => $this->rules->format3($offset, $glyphs, $i),
            default => null,
        };
        if (null === $rule) {
            return null;
        }
        [$inputLength, $records] = $rule;
        $end = $i + $inputLength;
        foreach ($records as [$sequenceIndex, $lookupIndex]) {
            $position = $i + $sequenceIndex;
            if ($position >= $end) {
                continue;
            }
            $before = count($glyphs);
            $result = $this->applyAt($lookupIndex, $glyphs, $position, $depth + 1);
            if (null === $result) {
                continue;
            }
            $glyphs = $result[0];
            $end    += count($glyphs) - $before;
        }

        return [$glyphs, max($i + 1, $end)];
    }

    /**
     * @param list<array{0: int, 1: list<int>}> $glyphs
     *
     * @return array{0: list<array{0: int, 1: list<int>}>, 1: int}|null
     */
    private function ligature(int $offset, array $glyphs, int $i): ?array
    {
        foreach ($this->tables->ligatureSet($offset)[$glyphs[$i][0] ?? -1] ?? [] as [$components, $ligature]) {
            $count = count($components);
            if (array_column(array_slice($glyphs, $i + 1, $count), column_key: 0) !== $components) {
                continue;
            }
            $codePoints = [];
            foreach (array_slice($glyphs, $i, $count + 1) as [, $points]) {
                foreach ($points as $point) {
                    $codePoints[] = $point;
                }
            }
            array_splice($glyphs, $i, $count + 1, [[$ligature, $codePoints]]);

            return [$glyphs, $i + 1];
        }

        return null;
    }

    /**
     * @param list<array{0: int, 1: list<int>}> $glyphs
     *
     * @return array{0: list<array{0: int, 1: list<int>}>, 1: int}|null
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
