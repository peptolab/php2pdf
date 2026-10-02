<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use function abs;
use function array_reverse;
use function min;

/**
 * Knuth and Plass's line breaking: chooses the breaks of a paragraph (up to its next forced
 * break) together, so that no line is much looser or tighter than the others, rather than filling
 * each line in turn. Word spaces stretch and shrink within their style's Justification limits
 * (133% and 80% of their width unless set, as InDesign's defaults); lines are scored by how far their spaces are stretched or shrunk,
 * with penalties for hyphens, consecutive hyphens and a loose line next to a tight one, as TeX
 * scores them. The last line is set at its natural width.
 *
 * @internal
 */
final class ParagraphComposer
{
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

    /** @var list<int> the positions a line may end at, in order */
    private array $breaks = [];

    /** @var array<int, float> the width of each line, by its number from $firstLine, as it is needed */
    private array $lineWidths = [];

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
                $format  = $item->format;
                $stretch += $item->spaceWidth() * $format->stretch;
                $shrink  += $item->spaceWidth() * $format->shrink;
            }
        }
        for ($i = $start; $i <= $end; ++$i) {
            if (! $this->isBreak($i)) {
                continue;
            }
            $this->breaks[] = $i;
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
        $items  = $paragraph->items;
        $end    = $start;
        $widest = min($widths->available($firstLine), $widths->available($firstLine + 1));
        while (null !== ($item = $items[$end] ?? null) && ! $item->isForcedBreak()) {
            if (ItemKind::Tab === $item->kind || (ItemKind::Glue !== $item->kind && $item->width > $widest)) {
                return null;
            }
            ++$end;
        }
        $composer = new self($items, $start, $end, $widths, $firstLine);

        return $composer->compose(self::TOLERANCE) ?? $composer->compose(null);
    }

    /**
     * A breakpoint at $position, carrying the running measures where the line after it starts:
     * past the glue it broke at.
     */
    private function breakpoint(
        int $position,
        int $line,
        int $fitness,
        float $demerits,
        bool $hyphen,
        ?Breakpoint $previous,
    ): Breakpoint {
        $from = $this->lineStart($position);

        return new Breakpoint(
            $position,
            $line,
            $fitness,
            $demerits,
            $hyphen,
            $previous,
            $this->natural[$from] ?? 0.0,
            $this->stretch[$from] ?? 0.0,
            $this->shrink[$from] ?? 0.0,
        );
    }

    /**
     * One pass of the algorithm: the cheapest breaks whose lines stay within the tolerance, or
     * with no tolerance any lines that are not too long, the looser the costlier.
     *
     * @return list<LineEnd>|null
     */
    private function compose(?float $tolerance): ?array
    {
        $active = [$this->breakpoint($this->start, 0, 1, 0.0, false, null)];
        foreach ($this->breaks as $i) {
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

    /** The width of line $line from the first, worked out once. */
    private function lineWidth(int $line): float
    {
        return $this->lineWidths[$line] = $this->widths->available($this->firstLine + $line);
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
        $extra    ??= 0.0;
        $last     = $i === $this->end;
        $natural  = $this->natural[$i] ?? 0.0;
        $stretchI = $this->stretch[$i] ?? 0.0;
        $shrinkI  = $this->shrink[$i] ?? 0.0;
        $survivor = [];
        $best     = [];
        foreach ($active as $node) {
            $lineWidth = $this->lineWidths[$node->line] ?? $this->lineWidth($node->line);
            $missing   = $lineWidth - $natural + $node->natural - $extra;
            $stretch   = $stretchI - $node->stretch;
            $shrink    = $shrinkI - $node->shrink;
            $ratio     = match (true) {
                $last && $missing >= -1.0e-6 => 0.0,
                $missing < 0.0 => $shrink > 0.0 ? $missing / $shrink : -2.0,
                $stretch > 0.0 => $missing / $stretch,
                default => $missing < 1.0e-6 ? 0.0 : 1.0e3,
            };
            if ($ratio >= -1.0) {
                $survivor[] = $node;
            }
            $badness = $last ? 0.0 : 100.0 * (abs($ratio) ** 3);
            if ($ratio < -1.0 || (null !== $tolerance && $badness > $tolerance)) {
                continue;
            }
            $fitness = $last
                ? 1
                : match (true) {
                    $ratio < -0.5 => 3,
                    $ratio <= 0.5 => 2,
                    $ratio <= 1.0 => 1,
                    default => 0,
                };
            $demerits =
                (self::LINE_PENALTY + $badness) ** 2
                + ($hyphen ? self::HYPHEN_PENALTY ** 2 : 0.0)
                + ($hyphen && $node->hyphen ? self::DOUBLE_HYPHEN : 0.0)
                + (abs($fitness - $node->fitness) > 1 ? self::FITNESS_PENALTY : 0.0)
                + $node->demerits;
            $current = $best[$fitness] ?? null;
            if (null === $current || $demerits < $current->demerits) {
                $best[$fitness] = $this->breakpoint($i, $node->line + 1, $fitness, $demerits, $hyphen, $node);
            }
        }

        return [$survivor, $best];
    }
}
