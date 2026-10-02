<?php

declare(strict_types=1);

namespace Php2Pdf\Font\Bidi;

use function count;
use function max;
use function min;

/**
 * The Unicode Bidirectional Algorithm (UAX #9) for one paragraph: resolves each character's
 * embedding level from its bidi class (and, for paired brackets, the bracket it pairs with), and
 * orders a line's characters for display. It passes the Unicode conformance tests
 * (BidiTest.txt and BidiCharacterTest.txt; see tools/bidi-conformance.php).
 *
 * @internal
 */
final class BidiParagraph
{
    public readonly int $level;

    /** @var list<int> resolved levels; characters rule X9 removes take the level before them */
    private array $levels;

    /**
     * @param list<int> $classes      bidi class per character, as BidiClass numbers them
     * @param list<int> $bracketTypes per character: 0 not a paired bracket, 1 opening, 2 closing
     * @param list<int> $bracketPairs per character: the closing bracket of its pair, with U+232A
     *                                given as U+3009
     * @param int|null  $level        the paragraph level, or null to take it from the text
     */
    public function __construct(
        private readonly array $classes,
        private readonly array $bracketTypes = [],
        private readonly array $bracketPairs = [],
        ?int $level = null,
    ) {
        $explicit     = new ExplicitLevels($classes, $level);
        $this->level  = $explicit->paragraphLevel;
        $this->levels = $explicit->levels;
        $sequences    = RunSequences::of(
            $classes,
            $explicit->levels,
            $explicit->matchingPdi,
            $explicit->matchingInitiator,
        );
        foreach ($sequences as $sequence) {
            $this->resolveSequence($sequence, $explicit);
        }
        $this->levelRemovedCharacters();
    }

    /**
     * Rules I1 and I2: how far a resolved type raises its level: right-to-left text and numbers in
     * left-to-right text by one (numbers by two), left-to-right text and numbers in right-to-left
     * text by one.
     */
    private static function implicitRaise(int $level, int $type): int
    {
        if (1 === ($level & 1)) {
            return BidiClass::R === $type ? 0 : 1;
        }

        return match ($type) {
            BidiClass::R                 => 1,
            BidiClass::AN, BidiClass::EN => 2,
            default                      => 0,
        };
    }

    private static function isTrailing(int $class): bool
    {
        return (
            BidiClass::WS === $class
                || BidiClass::PDI === $class
                || BidiClass::isIsolateInitiator($class)
                || BidiClass::isRemoved($class)
        );
    }

    /**
     * The order and levels with the stretch from $i up to $j reversed.
     *
     * @param list<int> $order
     * @param list<int> $levels
     *
     * @return array{0: list<int>, 1: list<int>}
     */
    private static function reverse(array $order, array $levels, int $i, int $j): array
    {
        for ($a = $i, $b = $j - 1; $a < $b; ++$a, --$b) {
            $o          = $order[$a] ?? 0;
            $order[$a]  = $order[$b] ?? 0;
            $order[$b]  = $o;
            $l          = $levels[$a] ?? 0;
            $levels[$a] = $levels[$b] ?? 0;
            $levels[$b] = $l;
        }

        return [$order, $levels];
    }

    /** Whether rule X9 removes the character at $i (an embedding control or boundary neutral). */
    public function isRemoved(int $i): bool
    {
        return BidiClass::isRemoved($this->classes[$i] ?? BidiClass::L);
    }

    /** @return list<int> */
    public function levels(): array
    {
        return $this->levels;
    }

    /**
     * The levels of a line, with rule L1 applied: separators, and whitespace and isolate controls
     * before them or at the end of the line, go back to the paragraph level.
     *
     * @return list<int>
     */
    public function lineLevels(int $start, int $end): array
    {
        $levels   = [];
        $trailing = true;
        for ($i = $end - 1; $i >= $start; --$i) {
            $class = $this->classes[$i] ?? BidiClass::L;
            $reset = BidiClass::S === $class || BidiClass::B === $class;
            if (! $reset && ! self::isTrailing($class)) {
                $trailing = false;
            }
            $trailing            = $trailing || $reset;
            $levels[$i - $start] = $trailing ? $this->level : $this->levels[$i] ?? $this->level;
        }
        $line = [];
        for ($k = 0; $k < ($end - $start); ++$k) {
            $line[] = $levels[$k] ?? $this->level;
        }

        return $line;
    }

    /**
     * The visual order of a line, left to right, as indices into the paragraph (rule L2): from the
     * highest level down to the lowest odd one, each run at that level or above is reversed.
     *
     * @return list<int>
     */
    public function visualOrder(int $start, int $end): array
    {
        $levels = $this->lineLevels($start, $end);
        $order  = [];
        for ($i = $start; $i < $end; ++$i) {
            $order[] = $i;
        }
        if ([] === $levels) {
            return $order;
        }
        $count = count($order);
        for ($level = max($levels); $level >= (min($levels) | 1); --$level) {
            for ($i = 0; $i < $count;) {
                if (($levels[$i] ?? 0) < $level) {
                    ++$i;
                    continue;
                }
                $j = $i;
                while ($j < $count && ($levels[$j] ?? 0) >= $level) {
                    ++$j;
                }
                [$order, $levels] = self::reverse($order, $levels, $i, $j);
                $i = $j;
            }
        }

        return $order;
    }

    /**
     * The types at the start and end of a sequence (sos and eos): from the higher of its level and
     * the level of the text before it, or after it unless it ends in an isolate initiator.
     *
     * @param list<int> $sequence
     *
     * @return array{0: int, 1: int}
     */
    private function boundaries(array $sequence, ExplicitLevels $explicit, int $level): array
    {
        $count = count($this->classes);
        $first = $sequence[0] ?? 0;
        $last  = $sequence[count($sequence) - 1] ?? 0;
        $prev  = $first - 1;
        while ($prev >= 0 && BidiClass::isRemoved($this->classes[$prev] ?? BidiClass::L)) {
            --$prev;
        }
        $next = $last + 1;
        while ($next < $count && BidiClass::isRemoved($this->classes[$next] ?? BidiClass::L)) {
            ++$next;
        }
        $before = $prev >= 0 ? $explicit->levels[$prev] ?? $this->level : $this->level;
        $after  = BidiClass::isIsolateInitiator($explicit->types[$last] ?? -1) || $next >= $count
            ? $this->level
            : $explicit->levels[$next] ?? $this->level;

        return [BidiClass::forLevel(max($before, $level)), BidiClass::forLevel(max($after, $level))];
    }

    /** Characters removed by rule X9 take the level of the character before them. */
    private function levelRemovedCharacters(): void
    {
        $previous = $this->level;
        foreach ($this->classes as $i => $class) {
            if (BidiClass::isRemoved($class)) {
                $this->levels[$i] = $previous;
                continue;
            }
            $previous = $this->levels[$i] ?? $this->level;
        }
    }

    /**
     * Rules W1 to W7, N0 to N2, I1 and I2 for one isolating run sequence; its start and end
     * types (sos and eos) come from the explicit levels around it.
     *
     * @param list<int> $sequence
     */
    private function resolveSequence(array $sequence, ExplicitLevels $explicit): void
    {
        $level    = $explicit->levels[$sequence[0] ?? 0] ?? $this->level;
        $types    = [];
        $brackets = [];
        $pairs    = [];
        foreach ($sequence as $i) {
            $types[]    = $explicit->types[$i] ?? BidiClass::L;
            $brackets[] = $this->bracketTypes[$i] ?? 0;
            $pairs[]    = $this->bracketPairs[$i] ?? -1;
        }
        [$sos, $eos] = $this->boundaries($sequence, $explicit, $level);
        $original = $types;
        $types    = WeakTypes::resolve($types, $sos);
        $types    = NeutralTypes::brackets($types, $original, $brackets, $pairs, $sos, $level);
        $types    = NeutralTypes::neutrals($types, $sos, $eos, $level);
        foreach ($sequence as $k => $i) {
            $this->levels[$i] = $level + self::implicitRaise($level, $types[$k] ?? BidiClass::L);
        }
    }
}
