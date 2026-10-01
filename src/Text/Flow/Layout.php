<?php

declare(strict_types=1);

namespace PhpPdf\Text\Flow;

use PhpPdf\Flow\FlowResult;
use PhpPdf\Text\FirstBaseline;
use PhpPdf\Text\FlowOptions;

use function array_slice;
use function count;
use function max;

/**
 * Fills a box of a given size with lines from a cursor position, applying paragraph spacing,
 * first-baseline placement, widow and orphan control and keep options. Produces positioned lines
 * without drawing, so layouts can be measured or retried at another scale.
 *
 * @internal
 */
final class Layout
{
    /** @var list<array{0: Line, 1: float}> lines with their baseline offset from the top */
    public array $lines = [];

    public float $height = 0.0;
    public int $paragraphIndex;
    public int $itemIndex;
    public FlowResult $result = FlowResult::Done;

    private float $y = 0.0;

    /** @param list<Paragraph> $paragraphs */
    public function __construct(
        private readonly array $paragraphs,
        int $paragraphIndex,
        int $itemIndex,
        private readonly float $width,
        private readonly float $boxHeight,
        private readonly FlowOptions $options,
    ) {
        $this->paragraphIndex = $paragraphIndex;
        $this->itemIndex      = $itemIndex;
    }

    public function run(): self
    {
        while (null !== ($paragraph = $this->paragraphs[$this->paragraphIndex] ?? null)) {
            $outcome = $this->placeParagraph($paragraph);
            if (null !== $outcome) {
                $this->result = $outcome;

                return $this;
            }
        }
        $this->result = FlowResult::Done;

        return $this;
    }

    /**
     * Drops lines so that neither the part left here nor the continuation is shorter than the
     * orphan and widow minimums, when the paragraph can be moved.
     *
     * @param list<array{0: Line, 1: float}> $lines
     * @param int                            $orphanFloor the minimum lines to keep here, or 0 when any cut is fine
     *
     * @return list<array{0: Line, 1: float}>
     */
    private function applyWidowsOrphans(Paragraph $paragraph, array $lines, int $orphanFloor): array
    {
        if ([] === $lines) {
            return [];
        }
        $widows = $paragraph->style->widows ?? 2;
        $placed = 0;
        foreach ($lines as [$line]) {
            $placed += $line->itemsConsumed;
        }
        $remaining = $this->countRemainingLines($paragraph, $this->itemIndex + $placed, $widows);
        $keep      = count($lines) - max(0, $widows - $remaining);
        if ($keep < $orphanFloor) {
            $keep = 0;
        }

        return array_slice($lines, offset: 0, length: max(0, $keep));
    }

    /**
     * Breaks lines of the paragraph from the cursor until the box is full or a break item stops it.
     */
    private function breakLines(Paragraph $paragraph): LineRun
    {
        $lines      = [];
        $consumed   = 0;
        $offset     = $this->itemIndex;
        $lineNumber = 0 === $offset ? 0 : 1;
        $y          = $this->y;
        while (null !== ($item = $paragraph->items[$offset] ?? null)) {
            if (ItemKind::ColumnBreak === $item->kind || ItemKind::PageBreak === $item->kind) {
                return new LineRun(
                    $lines,
                    $consumed,
                    ItemKind::PageBreak === $item->kind ? FlowResult::NextPage : FlowResult::BoxFull,
                    $y,
                );
            }
            $line = LineBreaker::nextLine($paragraph, $offset, $this->width, $lineNumber);
            if (null === $line) {
                break;
            }
            $firstInBox = [] === $this->lines && [] === $lines;
            $baseline   = $y + ($firstInBox ? $this->firstBaseline($line) : $line->lineHeight);
            if (($baseline + $line->descent) > ($this->boxHeight + 1.0e-6)) {
                break;
            }
            $lines[]  = [$line, $baseline];
            $y        = $baseline;
            $offset   += $line->itemsConsumed;
            $consumed += $line->itemsConsumed;
            ++$lineNumber;
        }
        return new LineRun($lines, $consumed, null, $y);
    }

    /** @param list<array{0: Line, 1: float}> $lines */
    private function commit(array $lines, int $consumed): void
    {
        foreach ($lines as [$line, $baseline]) {
            $this->lines[] = [$line, $baseline];
            $this->y       = $baseline;
            $this->height  = max($this->height, $baseline + $line->descent);
        }
        $this->itemIndex += $consumed;
    }

    private function countRemainingLines(Paragraph $paragraph, int $offset, int $limit): int
    {
        $count = 0;
        while ($count <= $limit) {
            $line = LineBreaker::nextLine($paragraph, $offset, $this->width, 1);
            if (null === $line) {
                break;
            }
            ++$count;
            $offset += $line->itemsConsumed;
        }

        return $count;
    }

    private function firstBaseline(Line $line): float
    {
        return match ($this->options->firstBaseline) {
            FirstBaseline::Ascender  => $line->ascent,
            FirstBaseline::CapHeight => $line->capHeight,
            FirstBaseline::XHeight   => $line->xHeight,
            FirstBaseline::Leading   => $line->lineHeight,
        };
    }

    /** Whether the next paragraph's first line still fits below the current position. */
    private function nextParagraphStarts(): bool
    {
        $next = $this->paragraphs[$this->paragraphIndex] ?? null;
        if (null === $next) {
            return true;
        }
        $line = LineBreaker::nextLine($next, 0, $this->width, 0);
        if (null === $line) {
            return true;
        }
        $baseline = $this->y + ($next->style->spaceBefore ?? 0.0) + $line->lineHeight;

        return ($baseline + $line->descent) <= ($this->boxHeight + 1.0e-6);
    }

    /**
     * Places as much of the current paragraph as fits; returns a stop reason, or null to go on.
     */
    private function placeParagraph(Paragraph $paragraph): ?FlowResult
    {
        $style    = $paragraph->style;
        $starting = 0 === $this->itemIndex;
        $atTop    = [] === $this->lines;
        $yStart   = $this->y;
        if ($starting && ! $atTop) {
            $this->y += $style->spaceBefore ?? 0.0;
        }
        $run       = $this->breakLines($paragraph);
        $remaining = count($paragraph->items) - $this->itemIndex - $run->consumed;
        $complete  = 0 === $remaining;

        if (null !== $run->stop) {
            // An explicit column or page break: keep what came before it and consume the break.
            $this->commit($run->lines, $run->consumed);
            $this->skipBreakItem($paragraph);

            return $run->stop;
        }
        if ($complete) {
            $this->commit($run->lines, $run->consumed);
            $this->y += $style->spaceAfter ?? 0.0;
            ++$this->paragraphIndex;
            $this->itemIndex = 0;
            if (true === $style->keepWithNext && ! $atTop && ! $this->nextParagraphStarts()) {
                $this->rollBack($paragraph, $run, $yStart);

                return FlowResult::BoxFull;
            }

            return null;
        }

        // The paragraph does not fit entirely: decide how much of it stays here.
        $lines = $run->lines;
        if (! $atTop && ($starting && true === $style->keepTogether)) {
            $lines = [];
        }
        $lines = $this->applyWidowsOrphans($paragraph, $lines, $starting && ! $atTop ? $style->orphans ?? 2 : 0);
        if ([] === $lines) {
            $this->y = $yStart;

            return $atTop ? FlowResult::BoxEmpty : FlowResult::BoxFull;
        }
        $consumed = 0;
        foreach ($lines as [$line]) {
            $consumed += $line->itemsConsumed;
        }
        $this->commit($lines, $consumed);

        return FlowResult::BoxFull;
    }

    /** Removes a committed paragraph again so it moves to the next box with its successor. */
    private function rollBack(Paragraph $paragraph, LineRun $run, float $yStart): void
    {
        $this->lines = array_slice($this->lines, offset: 0, length: count($this->lines) - count($run->lines));
        --$this->paragraphIndex;
        $this->itemIndex = count($paragraph->items) - $run->consumed;
        $this->y         = $yStart;
        $this->height    = 0.0;
        foreach ($this->lines as [$line, $baseline]) {
            $this->height = max($this->height, $baseline + $line->descent);
        }
        unset($paragraph);
    }

    private function skipBreakItem(Paragraph $paragraph): void
    {
        ++$this->itemIndex;
        if ($this->itemIndex >= count($paragraph->items)) {
            ++$this->paragraphIndex;
            $this->itemIndex = 0;
        }
    }
}
