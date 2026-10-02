<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use Php2Pdf\Exception\ParseException;

use function array_key_exists;
use function count;
use function max;

/**
 * Matches context (GSUB type 5, GPOS type 7) and chained context (GSUB type 6, GPOS type 8) rules
 * at a position: by glyph (format 1), by glyph class (format 2) or by coverage per position
 * (format 3). Glyphs the lookup ignores are passed over in the backtrack, input and lookahead.
 * A match gives the positions of the input glyphs and the nested lookups to apply at them.
 *
 * @internal
 */
final readonly class ChainRules
{
    private const int BACKTRACK = 0;
    private const int INPUT     = 1;
    private const int LOOKAHEAD = 2;

    public function __construct(
        private LayoutTables $tables,
    ) {}

    /**
     * The next glyph after $position that the filter does not ignore, or null.
     *
     * @param list<int> $ids
     */
    public static function next(array $ids, int $position, GlyphFilter $filter): ?int
    {
        for ($p = $position + 1; array_key_exists($p, $ids); ++$p) {
            if (! $filter->ignores($ids[$p])) {
                return $p;
            }
        }

        return null;
    }

    /**
     * The glyph before $position that the filter does not ignore, or null.
     *
     * @param list<int> $ids
     */
    public static function previous(array $ids, int $position, GlyphFilter $filter): ?int
    {
        for ($p = $position - 1; $p >= 0; --$p) {
            if (! $filter->ignores($ids[$p] ?? 0)) {
                return $p;
            }
        }

        return null;
    }

    /**
     * A glyph as a rule compares it: itself, or its class in one part's class definition.
     *
     * @param array{0: array<int, int>, 1: array<int, int>, 2: array<int, int>}|null $classes
     */
    private static function value(?array $classes, int $part, int $glyph): int
    {
        return null === $classes ? $glyph : $classes[$part][$glyph] ?? 0;
    }

    /**
     * Matches a context or chained context subtable at $i: the input positions and the lookup
     * records, or null.
     *
     * @param list<int> $ids
     *
     * @return array{0: list<int>, 1: list<array{0: int, 1: int}>}|null
     *
     * @throws ParseException
     */
    public function match(int $type, int $offset, array $ids, int $i, GlyphFilter $filter): ?array
    {
        $chained = $this->tables->chainType === $type;

        return match ($this->tables->reader->u16($offset)) {
            1       => $this->format1($offset, $ids, $i, $filter, $type),
            2       => $this->format2($offset, $ids, $i, $filter, $type),
            3 => $chained
                ? $this->chainFormat3($offset, $ids, $i, $filter)
                : $this->contextFormat3($offset, $ids, $i, $filter),
            default => null,
        };
    }

    /**
     * Whether the backtrack before $first and the lookahead after $last match.
     *
     * @param list<int>                                                         $ids
     * @param array{0: array<int, int>, 1: array<int, int>, 2: array<int, int>}|null $classes
     * @param list<int>                                                         $backtrack
     * @param list<int>                                                         $lookahead
     */
    private function around(
        array $ids,
        int $first,
        int $last,
        GlyphFilter $filter,
        ?array $classes,
        array $backtrack,
        array $lookahead,
    ): bool {
        $p = $first;
        foreach ($backtrack as $expected) {
            $p = self::previous($ids, $p, $filter);
            if (null === $p || self::value($classes, self::BACKTRACK, $ids[$p] ?? -1) !== $expected) {
                return false;
            }
        }
        $p = $last;
        foreach ($lookahead as $expected) {
            $p = self::next($ids, $p, $filter);
            if (null === $p || self::value($classes, self::LOOKAHEAD, $ids[$p] ?? -1) !== $expected) {
                return false;
            }
        }

        return true;
    }

    /**
     * Format 3 of chained context: one coverage per backtrack, input and lookahead position.
     *
     * @param list<int> $ids
     *
     * @return array{0: list<int>, 1: list<array{0: int, 1: int}>}|null
     *
     * @throws ParseException
     */
    private function chainFormat3(int $offset, array $ids, int $i, GlyphFilter $filter): ?array
    {
        $r              = $this->tables->reader;
        $at             = $offset + 2;
        $backtrackCount = $r->u16($at);
        $backtrack      = $r->u16List($at + 2, $backtrackCount);
        $at             += 2 + (2 * $backtrackCount);
        $inputCount     = $r->u16($at);
        $input          = $r->u16List($at + 2, $inputCount);
        $at             += 2 + (2 * $inputCount);
        $lookaheadCount = $r->u16($at);
        $lookahead      = $r->u16List($at + 2, $lookaheadCount);
        $at             += 2 + (2 * $lookaheadCount);
        $covered        = fn(int $coverage, ?int $position): bool => null !== $position
        && array_key_exists(
            $ids[$position] ?? -1,
            $this->tables->coverage($offset + $coverage),
        );
        $positions = $this->sequence($ids, $i, $inputCount, $filter);
        if (null === $positions) {
            return null;
        }
        foreach ($input as $k => $coverage) {
            if (! $covered($coverage, $positions[$k] ?? null)) {
                return null;
            }
        }
        $p = $i;
        foreach ($backtrack as $coverage) {
            $p = self::previous($ids, $p, $filter);
            if (! $covered($coverage, $p)) {
                return null;
            }
            $p ??= 0;
        }
        $p = $positions[$inputCount - 1] ?? $i;
        foreach ($lookahead as $coverage) {
            $p = self::next($ids, $p, $filter);
            if (! $covered($coverage, $p)) {
                return null;
            }
            $p ??= 0;
        }

        return [$positions, $this->tables->lookupRecords($at + 2, $r->u16($at))];
    }

    /**
     * Format 3 of context: one coverage per input position.
     *
     * @param list<int> $ids
     *
     * @return array{0: list<int>, 1: list<array{0: int, 1: int}>}|null
     *
     * @throws ParseException
     */
    private function contextFormat3(int $offset, array $ids, int $i, GlyphFilter $filter): ?array
    {
        $r          = $this->tables->reader;
        $inputCount = $r->u16($offset + 2);
        $positions  = $this->sequence($ids, $i, $inputCount, $filter);
        if (null === $positions) {
            return null;
        }
        foreach ($r->u16List($offset + 6, $inputCount) as $k => $coverage) {
            if (! array_key_exists($ids[$positions[$k] ?? -1] ?? -1, $this->tables->coverage($offset + $coverage))) {
                return null;
            }
        }

        return [$positions, $this->tables->lookupRecords($offset + 6 + (2 * $inputCount), $r->u16($offset + 4))];
    }

    /**
     * Format 1: rules by glyph, in sets by the first glyph's coverage index.
     *
     * @param list<int> $ids
     *
     * @return array{0: list<int>, 1: list<array{0: int, 1: int}>}|null
     *
     * @throws ParseException
     */
    private function format1(int $offset, array $ids, int $i, GlyphFilter $filter, int $type): ?array
    {
        $r     = $this->tables->reader;
        $index = $this->tables->coverage($offset + $r->u16($offset + 2))[$ids[$i] ?? -1] ?? null;
        if (null === $index || $index >= $r->u16($offset + 4)) {
            return null;
        }
        $set = $offset + $r->u16($offset + 6 + (2 * $index));
        foreach ($r->u16List($set + 2, $r->u16($set)) as $ruleOffset) {
            $rule = $this->rule($set + $ruleOffset, $ids, $i, $filter, null, $type);
            if (null !== $rule) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Format 2: rules by glyph class, in sets by the first glyph's input class.
     *
     * @param list<int> $ids
     *
     * @return array{0: list<int>, 1: list<array{0: int, 1: int}>}|null
     *
     * @throws ParseException
     */
    private function format2(int $offset, array $ids, int $i, GlyphFilter $filter, int $type): ?array
    {
        $r = $this->tables->reader;
        if (null === ($this->tables->coverage($offset + $r->u16($offset + 2))[$ids[$i] ?? -1] ?? null)) {
            return null;
        }
        [$classes, $sets] = $this->tables->chainType === $type
            ? [
                [
                    $this->tables->classDef($offset + $r->u16($offset + 4)),
                    $this->tables->classDef($offset + $r->u16($offset + 6)),
                    $this->tables->classDef($offset + $r->u16($offset + 8)),
                ],
                $offset + 10,
            ]
            : [
                [
                    $this->tables->classDef($offset + $r->u16($offset + 4)),
                    $this->tables->classDef($offset + $r->u16($offset + 4)),
                    $this->tables->classDef($offset + $r->u16($offset + 4)),
                ],
                $offset + 6,
            ];
        $class = $classes[self::INPUT][$ids[$i] ?? -1] ?? 0;
        if ($class >= $r->u16($sets)) {
            return null;
        }
        $setOffset = $r->u16($sets + 2 + (2 * $class));
        if (0 === $setOffset) {
            return null;
        }
        $set = $offset + $setOffset;
        foreach ($r->u16List($set + 2, $r->u16($set)) as $ruleOffset) {
            $rule = $this->rule($set + $ruleOffset, $ids, $i, $filter, $classes, $type);
            if (null !== $rule) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * The positions of the input sequence when the glyphs after $i match $input.
     *
     * @param list<int>                                                         $ids
     * @param array{0: array<int, int>, 1: array<int, int>, 2: array<int, int>}|null $classes
     * @param list<int>                                                         $input
     *
     * @return list<int>|null
     */
    private function input(array $ids, int $i, GlyphFilter $filter, ?array $classes, array $input): ?array
    {
        $positions = $this->sequence($ids, $i, count($input) + 1, $filter);
        if (null === $positions) {
            return null;
        }
        foreach ($input as $k => $expected) {
            if (self::value($classes, self::INPUT, $ids[$positions[$k + 1] ?? -1] ?? -1) !== $expected) {
                return null;
            }
        }

        return $positions;
    }

    /**
     * A format 1 or 2 rule matched at $i: by glyph, or by class through the class definitions.
     * Chained rules have backtrack and lookahead sequences around the input; context rules have
     * the input alone.
     *
     * @param list<int>                                                         $ids
     * @param array{0: array<int, int>, 1: array<int, int>, 2: array<int, int>}|null $classes
     *
     * @return array{0: list<int>, 1: list<array{0: int, 1: int}>}|null
     *
     * @throws ParseException
     */
    private function rule(int $rule, array $ids, int $i, GlyphFilter $filter, ?array $classes, int $type): ?array
    {
        $r = $this->tables->reader;
        if ($this->tables->chainType !== $type) {
            $inputCount = $r->u16($rule);
            $input      = $r->u16List($rule + 4, max(0, $inputCount - 1));
            $records    = $rule + 4 + (2 * max(0, $inputCount - 1));
            $positions  = $this->input($ids, $i, $filter, $classes, $input);

            return (
                null === $positions ? null : [$positions, $this->tables->lookupRecords($records, $r->u16($rule + 2))]
            );
        }
        $backtrack  = $r->u16List($rule + 2, $r->u16($rule));
        $at         = $rule + 2 + (2 * count($backtrack));
        $inputCount = $r->u16($at);
        $input      = $r->u16List($at + 2, max(0, $inputCount - 1));
        $at         += 2 + (2 * max(0, $inputCount - 1));
        $lookahead  = $r->u16List($at + 2, $r->u16($at));
        $at         += 2 + (2 * count($lookahead));
        $positions  = $this->input($ids, $i, $filter, $classes, $input);
        if (
            null === $positions
            || ! $this->around(
                $ids,
                $i,
                $positions[count($positions) - 1] ?? $i,
                $filter,
                $classes,
                $backtrack,
                $lookahead,
            )
        ) {
            return null;
        }

        return [$positions, $this->tables->lookupRecords($at + 2, $r->u16($at))];
    }

    /**
     * The positions of an input sequence of $count glyphs from $i, passing over ignored glyphs.
     *
     * @param list<int> $ids
     *
     * @return list<int>|null
     */
    private function sequence(array $ids, int $i, int $count, GlyphFilter $filter): ?array
    {
        if ($count < 1) {
            return null;
        }
        $positions = [$i];
        $p         = $i;
        for ($k = 1; $k < $count; ++$k) {
            $p = self::next($ids, $p, $filter);
            if (null === $p) {
                return null;
            }
            $positions[] = $p;
        }

        return $positions;
    }
}
