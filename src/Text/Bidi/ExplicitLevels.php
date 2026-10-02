<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Bidi;

use function array_fill;
use function array_pop;
use function count;

/**
 * Rules P2, P3 and X1 to X8 of the bidi algorithm: the paragraph level, each isolate's matching
 * PDI, and the levels and overridden classes that embeddings, overrides and isolates give, to the
 * algorithm's depth of 125.
 *
 * @internal
 */
final class ExplicitLevels
{
    private const int MAX_DEPTH = 125;

    public readonly int $paragraphLevel;

    /** @var list<int> */
    public array $levels;

    /** @var list<int> classes after overrides */
    public array $types;

    /** @var array<int, int> isolate initiator => its matching PDI */
    public array $matchingPdi = [];

    /** @var array<int, int> PDI => its isolate initiator */
    public array $matchingInitiator = [];

    /** @var list<array{0: int, 1: int, 2: bool}> level, override class (ON for none), isolate */
    private array $stack;

    private int $overflowIsolates   = 0;
    private int $overflowEmbeddings = 0;
    private int $validIsolates      = 0;

    /** @param list<int> $classes */
    public function __construct(
        private readonly array $classes,
        ?int $level,
    ) {
        $this->matchIsolates();
        $this->paragraphLevel = $level ?? $this->firstStrongLevel(0, count($classes));
        $this->types          = $classes;
        $this->levels         = [] === $classes ? [] : array_fill(0, count($classes), $this->paragraphLevel);
        $this->stack          = [[$this->paragraphLevel, BidiClass::ON, false]];
        foreach ($classes as $i => $class) {
            match (true) {
                BidiClass::PDI === $class => $this->popIsolate($i),
                BidiClass::PDF === $class => $this->popEmbedding($i),
                BidiClass::B === $class => $this->levels[$i] = $this->paragraphLevel,
                self::isPush($class) => $this->push($i, $class),
                default => $this->set($i, $class),
            };
        }
    }

    private static function isPush(int $class): bool
    {
        return BidiClass::RLE === $class
            || BidiClass::LRE === $class
            || BidiClass::RLO === $class
            || BidiClass::LRO === $class
            || BidiClass::isIsolateInitiator($class);
    }

    /**
     * Rules P2 and P3: the level of the first strong character between $start and $end, passing
     * over isolates; 0 when there is none.
     */
    public function firstStrongLevel(int $start, int $end): int
    {
        for ($i = $start; $i < $end; ++$i) {
            $class = $this->classes[$i] ?? BidiClass::L;
            if (BidiClass::L === $class) {
                return 0;
            }
            if (BidiClass::R === $class || BidiClass::AL === $class) {
                return 1;
            }
            if (BidiClass::isIsolateInitiator($class)) {
                $i = $this->matchingPdi[$i] ?? $end;
            }
        }

        return 0;
    }

    /** Rule BD9: each isolate initiator's matching PDI. */
    private function matchIsolates(): void
    {
        /** @var list<int> $open */
        $open = [];
        foreach ($this->classes as $i => $class) {
            if (BidiClass::isIsolateInitiator($class)) {
                $open[] = $i;
                continue;
            }
            if (BidiClass::B === $class) {
                $open = [];
                continue;
            }
            $initiator = BidiClass::PDI === $class ? array_pop($open) : null;
            if (null === $initiator) {
                continue;
            }
            $this->matchingPdi[$initiator] = $i;
            $this->matchingInitiator[$i]   = $initiator;
        }
    }

    /** Rule X7: a PDF ends the last embedding or override, unless it is an isolate's. */
    private function popEmbedding(int $i): void
    {
        $top              = $this->top();
        $this->levels[$i] = $top[0];
        if ($this->overflowIsolates > 0) {
            return;
        }
        if ($this->overflowEmbeddings > 0) {
            --$this->overflowEmbeddings;

            return;
        }
        if (! $top[2] && count($this->stack) >= 2) {
            array_pop($this->stack);
        }
    }

    /** Rule X6a: a PDI ends its isolate and the embeddings inside it. */
    private function popIsolate(int $i): void
    {
        if ($this->overflowIsolates > 0) {
            --$this->overflowIsolates;
            $this->set($i, BidiClass::PDI);

            return;
        }
        if (0 !== $this->validIsolates) {
            $this->overflowEmbeddings = 0;
            while (! $this->top()[2]) {
                array_pop($this->stack);
            }
            array_pop($this->stack);
            --$this->validIsolates;
        }
        $this->set($i, BidiClass::PDI);
    }

    /** Rules X2 to X5c: an embedding, override or isolate opens a level, when one is left. */
    private function push(int $i, int $class): void
    {
        $top     = $this->top();
        $isolate = BidiClass::isIsolateInitiator($class);
        $rtl     = BidiClass::RLE === $class || BidiClass::RLO === $class || BidiClass::RLI === $class;
        if (BidiClass::FSI === $class) {
            $rtl = 1 === $this->firstStrongLevel($i + 1, $this->matchingPdi[$i] ?? count($this->classes));
        }
        $this->levels[$i] = $top[0];
        if ($isolate && BidiClass::ON !== $top[1]) {
            $this->types[$i] = $top[1];
        }
        $next = $rtl ? ($top[0] + 1) | 1 : ($top[0] + 2) & ~1;
        if ($next > self::MAX_DEPTH || 0 !== $this->overflowIsolates || 0 !== $this->overflowEmbeddings) {
            if ($isolate) {
                ++$this->overflowIsolates;

                return;
            }
            $this->overflowEmbeddings += 0 === $this->overflowIsolates ? 1 : 0;

            return;
        }
        $this->validIsolates += $isolate ? 1 : 0;
        $this->stack[]       = [
            $next,
            match ($class) {
                BidiClass::LRO => BidiClass::L,
                BidiClass::RLO => BidiClass::R,
                default        => BidiClass::ON,
            },
            $isolate,
        ];
        if (! $isolate) {
            $this->levels[$i] = $next;
        }
    }

    /** Rule X6: a character takes the current level, and the current override's class. */
    private function set(int $i, int $class): void
    {
        $top              = $this->top();
        $this->levels[$i] = $top[0];
        if (BidiClass::ON !== $top[1] && ! BidiClass::isRemoved($class)) {
            $this->types[$i] = $top[1];
        }
    }

    /** @return array{0: int, 1: int, 2: bool} */
    private function top(): array
    {
        return $this->stack[count($this->stack) - 1] ?? [$this->paragraphLevel, BidiClass::ON, false];
    }
}
