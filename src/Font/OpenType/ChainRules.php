<?php

declare(strict_types=1);

namespace PhpPdf\Font\OpenType;

use function array_key_exists;
use function max;

/**
 * Matches chained context substitution rules (GSUB lookup type 6) at a position: by glyph
 * (format 1), by glyph class (format 2) or by coverage per position (format 3). A match gives the
 * length of the input sequence and the nested lookups to apply within it.
 *
 * @internal
 */
final readonly class ChainRules
{
    private const int BACKTRACK = 0;
    private const int INPUT     = 1;
    private const int LOOKAHEAD = 2;

    public function __construct(
        private GsubTables $tables,
    ) {}

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
     * Format 1: rules by glyph. Returns the input length and the substitution records, or null.
     *
     * @param list<array{0: int, 1: list<int>}> $glyphs
     *
     * @return array{0: int, 1: list<array{0: int, 1: int}>}|null
     */
    public function format1(int $offset, array $glyphs, int $i): ?array
    {
        $r     = $this->tables->reader;
        $index = $this->tables->coverage($offset + $r->u16($offset + 2))[$glyphs[$i][0] ?? -1] ?? null;
        if (null === $index || $index >= $r->u16($offset + 4)) {
            return null;
        }
        $set = $offset + $r->u16($offset + 6 + (2 * $index));
        foreach ($r->u16List($set + 2, $r->u16($set)) as $ruleOffset) {
            $rule = $this->chainRule($set + $ruleOffset, $glyphs, $i, null);
            if (null !== $rule) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Format 2: rules by glyph class.
     *
     * @param list<array{0: int, 1: list<int>}> $glyphs
     *
     * @return array{0: int, 1: list<array{0: int, 1: int}>}|null
     */
    public function format2(int $offset, array $glyphs, int $i): ?array
    {
        $r = $this->tables->reader;
        if (null === ($this->tables->coverage($offset + $r->u16($offset + 2))[$glyphs[$i][0] ?? -1] ?? null)) {
            return null;
        }
        $backtrack = $this->tables->classDef($offset + $r->u16($offset + 4));
        $input     = $this->tables->classDef($offset + $r->u16($offset + 6));
        $lookahead = $this->tables->classDef($offset + $r->u16($offset + 8));
        $class     = $input[$glyphs[$i][0] ?? -1] ?? 0;
        if ($class >= $r->u16($offset + 10)) {
            return null;
        }
        $setOffset = $r->u16($offset + 12 + (2 * $class));
        if (0 === $setOffset) {
            return null;
        }
        $set = $offset + $setOffset;
        foreach ($r->u16List($set + 2, $r->u16($set)) as $ruleOffset) {
            $rule = $this->chainRule(
                $set + $ruleOffset,
                $glyphs,
                $i,
                [$backtrack, $input, $lookahead],
            );
            if (null !== $rule) {
                return $rule;
            }
        }

        return null;
    }

    /**
     * Format 3: one coverage per position.
     *
     * @param list<array{0: int, 1: list<int>}> $glyphs
     *
     * @return array{0: int, 1: list<array{0: int, 1: int}>}|null
     */
    public function format3(int $offset, array $glyphs, int $i): ?array
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
        $covered        = fn(int $coverage, int $position): bool => array_key_exists(
            $glyphs[$position][0] ?? -1,
            $this->tables->coverage($offset + $coverage),
        );
        foreach ($backtrack as $k => $coverage) {
            if (($i - 1 - $k) < 0 || ! $covered($coverage, $i - 1 - $k)) {
                return null;
            }
        }
        foreach ($input as $k => $coverage) {
            if (! $covered($coverage, $i + $k)) {
                return null;
            }
        }
        foreach ($lookahead as $k => $coverage) {
            if (! $covered($coverage, $i + $inputCount + $k)) {
                return null;
            }
        }

        return [$inputCount, $this->tables->substRecords($at)];
    }

    /**
     * A format 1 or 2 chain rule matched at $i: by glyph, or by class through the backtrack, input
     * and lookahead class definitions.
     *
     * @param list<array{0: int, 1: list<int>}>                                $glyphs
     * @param array{0: array<int, int>, 1: array<int, int>, 2: array<int, int>}|null $classes
     *
     * @return array{0: int, 1: list<array{0: int, 1: int}>}|null
     */
    private function chainRule(int $rule, array $glyphs, int $i, ?array $classes): ?array
    {
        $r              = $this->tables->reader;
        $backtrackCount = $r->u16($rule);
        $backtrack      = $r->u16List($rule + 2, $backtrackCount);
        $at             = $rule + 2 + (2 * $backtrackCount);
        $inputCount     = $r->u16($at);
        $input          = $r->u16List($at + 2, max(0, $inputCount - 1));
        $at             += 2 + (2 * max(0, $inputCount - 1));
        $lookaheadCount = $r->u16($at);
        $lookahead      = $r->u16List($at + 2, $lookaheadCount);
        $at             += 2 + (2 * $lookaheadCount);
        foreach ($backtrack as $k => $expected) {
            $glyph = $glyphs[$i - 1 - $k][0] ?? null;
            if (null === $glyph || ($i - 1 - $k) < 0 || self::value($classes, self::BACKTRACK, $glyph) !== $expected) {
                return null;
            }
        }
        foreach ($input as $k => $expected) {
            $glyph = $glyphs[$i + 1 + $k][0] ?? null;
            if (null === $glyph || self::value($classes, self::INPUT, $glyph) !== $expected) {
                return null;
            }
        }
        foreach ($lookahead as $k => $expected) {
            $glyph = $glyphs[$i + $inputCount + $k][0] ?? null;
            if (null === $glyph || self::value($classes, self::LOOKAHEAD, $glyph) !== $expected) {
                return null;
            }
        }

        return [$inputCount, $this->tables->substRecords($at)];
    }
}
