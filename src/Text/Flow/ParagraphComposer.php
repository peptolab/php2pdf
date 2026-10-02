<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use function abs;
use function array_reverse;
use function min;

/**
 * Knuth and Plass's line breaking: chooses the breaks of a paragraph (up to its next forced
 * break) together, so that no line is much looser or tighter than the others, rather than filling
 * each line in turn. Word spaces stretch to 133% and shrink to 80% of their width, as InDesign's
 * default word spacing allows; lines are scored by how far their spaces are stretched or shrunk,
 * with penalties for hyphens, consecutive hyphens and a loose line next to a tight one, as TeX
 * scores them. The last line is set at its natural width.
 *
 * @internal
 */
final class ParagraphComposer
{
    /** Word spaces' stretch and shrink, as fractions of their width. */
    private const float STRETCH = 0.33;
    private const float SHRINK  = 0.2;

    /** The tolerance of the first pass; the second accepts any line that is not too long. */
    private const float TOLERANCE = 200.0;

    /** TeX's line, hyphen and pairing demerits. */
    private const float LINE_PENALTY    = 10.0;
    private const float HYPHEN_PENALTY  = 50.0;
    private const float DOUBLE_HYPHEN   = 3000.0;
    private const float FITNESS_PENALTY = 3000.0;

    /** @var array<int, float> running natural width to each item from the start */
    private array $natural = [];

    /** @var array<int, float> running stretch */
    private array $stretch = [];

    /** @var array<int, float> running shrink */
    private array $shrink = [];

    /** @param array<int, Item> $items */
    private function __construct(
        private readonly array $items,
        private readonly int $start,
        private readonly int $end,
        private readonly LineWidths $widths,
        private readonly int $firstLine,
    ) {
        $natural = 0.0;
        $stretch = 0.0;
        $shrink  = 0.0;
        for ($i = $start; $i <= $end; ++$i) {
            $this->natural[$i] = $natural;
            $this->stretch[$i] = $stretch;
            $this->shrink[$i]  = $shrink;
            $item              = $items[$i] ?? null;
            if (null === $item || ItemKind::Penalty === $item->kind) {
                continue;
            }
            $natural += $item->width;
            if (ItemKind::Glue === $item->kind) {
                $stretch += $item->width * self::STRETCH;
                $shrink  += $item->width * self::SHRINK;
            }
        }
    }

    /**
     * The ends of the lines from $start to the paragraph's next forced break, or null when the
     * text needs the line-by-line breaker: it has a tab or a word wider than a line.
     *
     * @param int $firstLine the number in the paragraph of the line starting at $start
     *
     * @return list<LineEnd>|null
     */
    public static function breaks(Paragraph $paragraph, int $start, LineWidths $widths, int $firstLine): ?array
    {
        $items = $paragraph->items;
        $end   = $start;
        while (null !== ($item = $items[$end] ?? null) && ! $item->isForcedBreak()) {
            if (
                ItemKind::Tab === $item->kind
                || (
                    ItemKind::Glue !== $item->kind
                    && $item->width > min($widths->available($firstLine), $widths->available($firstLine + 1))
                )
            ) {
                return null;
            }
            ++$end;
        }
        $composer = new self($items, $start, $end, $widths, $firstLine);

        return $composer->compose(self::TOLERANCE) ?? $composer->compose(null);
    }

    /** TeX's fitness classes: very loose, loose, decent, tight. */
    private static function fitness(float $ratio): int
    {
        return match (true) {
            $ratio < -0.5 => 3,
            $ratio <= 0.5 => 2,
            $ratio <= 1.0 => 1,
            default => 0,
        };
    }

    /**
     * One pass of the algorithm: the cheapest breaks whose lines stay within the tolerance, or
     * with no tolerance any lines that are not too long, the looser the costlier.
     *
     * @return list<LineEnd>|null
     */
    private function compose(?float $tolerance): ?array
    {
        $active = [new Breakpoint($this->start, 0, 1, 0.0, false, null)];
        for ($i = $this->start; $i <= $this->end; ++$i) {
            if (! $this->isBreak($i)) {
                continue;
            }
            [$active, $best] = $this->tryBreak($i, $active, $tolerance);
            foreach ($best as $node) {
                $active[] = $node;
            }
            if ([] === $active) {
                return null;
            }
        }
        $chosen = null;
        foreach ($active as $node) {
            if ($this->end !== $node->position || (null !== $chosen && $node->demerits >= $chosen->demerits)) {
                continue;
            }
            $chosen = $node;
        }

        return null === $chosen ? null : $this->ends($chosen);
    }

    /**
     * The line ends of the way to a breakpoint.
     *
     * @return list<LineEnd>
     */
    private function ends(Breakpoint $node): array
    {
        $ends = [];
        while (null !== $node->previous) {
            $item   = $this->items[$node->position] ?? null;
            $ends[] = new LineEnd(
                $node->position,
                null !== $item && ItemKind::Penalty === $item->kind ? $item : null,
                null !== $item && $item->isForcedBreak(),
            );
            $node = $node->previous;
        }

        return array_reverse($ends);
    }

    /** Whether a line may end at $i: at glue after a box, at a hyphenation penalty, or at the end. */
    private function isBreak(int $i): bool
    {
        if ($i === $this->end) {
            return true;
        }
        $item = $this->items[$i] ?? null;
        if (null === $item || $i === $this->start) {
            return false;
        }

        return (
            ItemKind::Penalty === $item->kind
                || (ItemKind::Glue === $item->kind && ItemKind::Glue !== ($this->items[$i - 1] ?? null)?->kind)
        );
    }

    /** Where a line after a break starts: past the glue it broke at. */
    private function lineStart(int $position): int
    {
        if ($position === $this->start) {
            return $position;
        }
        while ($position < $this->end && ItemKind::Glue === ($this->items[$position] ?? null)?->kind) {
            ++$position;
        }

        return $position;
    }

    /**
     * How far the spaces of the line from $from to $to stretch (positive) or shrink (negative) to
     * fill it, as a fraction of what they can; the last line is set at its natural width.
     */
    private function ratio(int $from, int $to, float $lineWidth, float $extra): float
    {
        $missing = $lineWidth - ($this->natural[$to] ?? 0.0) + ($this->natural[$from] ?? 0.0) - $extra;
        $stretch = ($this->stretch[$to] ?? 0.0) - ($this->stretch[$from] ?? 0.0);
        $shrink  = ($this->shrink[$to] ?? 0.0) - ($this->shrink[$from] ?? 0.0);
        if ($to === $this->end && $missing >= -1.0e-6) {
            return 0.0;
        }
        if ($missing < 0.0) {
            return $shrink > 0.0 ? $missing / $shrink : -2.0;
        }
        if ($stretch > 0.0) {
            return $missing / $stretch;
        }

        return $missing < 1.0e-6 ? 0.0 : 1.0e3;
    }

    /**
     * Tries every active breakpoint as the start of a line ending at $i: those that can still
     * reach later breaks stay active, and the cheapest way to $i in each fitness class is new.
     *
     * @param list<Breakpoint> $active
     *
     * @return array{0: list<Breakpoint>, 1: array<int, Breakpoint>}
     */
    private function tryBreak(int $i, array $active, ?float $tolerance): array
    {
        $item     = $this->items[$i] ?? null;
        $extra    = null !== $item && ItemKind::Penalty === $item->kind ? $item->hyphenWidth : null;
        $hyphen   = null !== $extra;
        $last     = $i === $this->end;
        $survivor = [];
        $best     = [];
        foreach ($active as $node) {
            $lineWidth = $this->widths->available($this->firstLine + $node->line);
            $ratio     = $this->ratio($this->lineStart($node->position), $i, $lineWidth, $extra ?? 0.0);
            if ($ratio >= -1.0) {
                $survivor[] = $node;
            }
            $badness = $last ? 0.0 : 100.0 * (abs($ratio) ** 3);
            if ($ratio < -1.0 || (null !== $tolerance && $badness > $tolerance)) {
                continue;
            }
            $fitness  = $last ? 1 : self::fitness($ratio);
            $demerits = (self::LINE_PENALTY + $badness) ** 2
            + ($hyphen ? self::HYPHEN_PENALTY ** 2 : 0.0)
            + ($hyphen && $node->hyphen ? self::DOUBLE_HYPHEN : 0.0)
            + (abs($fitness - $node->fitness) > 1 ? self::FITNESS_PENALTY : 0.0)
            + $node->demerits;
            $current = $best[$fitness] ?? null;
            if (null === $current || $demerits < $current->demerits) {
                $best[$fitness] = new Breakpoint($i, $node->line + 1, $fitness, $demerits, $hyphen, $node);
            }
        }

        return [$survivor, $best];
    }
}
