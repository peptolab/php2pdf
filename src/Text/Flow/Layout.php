<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Text\FirstBaseline;
use Php2Pdf\Text\FlowOptions;
use Php2Pdf\Text\Style\ParagraphFormat;

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
    /** @var list<PlacedLine> */
    private array $lines = [];

    private float $height = 0.0;
    private int $paragraphIndex;
    private int $itemIndex;

    /** The characters of the item at the cursor already placed: a box split across lines. */
    private int $carry;

    private float $y = 0.0;

    /** @param list<Paragraph> $paragraphs */
    public function __construct(
        private readonly array $paragraphs,
        FlowCursor $cursor,
        private readonly float $width,
        private readonly float $boxHeight,
        private readonly FlowOptions $options,
        private readonly LineBreaker $breaker,
    ) {
        $this->paragraphIndex = $cursor->paragraph;
        $this->itemIndex      = $cursor->item;
        $this->carry          = $cursor->carry;
    }

    /**
     * Where lines from the cursor end: the whole items they take, and the carry into the next.
     *
     * @param list<array{0: Line, 1: float}> $lines
     *
     * @return array{0: int, 1: int}
     */
    private static function advance(array $lines, int $carry): array
    {
        $consumed = 0;
        $end      = $carry;
        foreach ($lines as [$line]) {
            $consumed += $line->itemsConsumed;
            $end      = $line->carry;
        }

        return [$consumed, $end];
    }

    public function run(): LayoutResult
    {
        if ($this->options->avoidEmptyBegin) {
            $this->skipEmptyBegin();
        }
        $result = FlowResult::Done;
        while (null !== ($paragraph = $this->paragraphs[$this->paragraphIndex] ?? null)) {
            $outcome = $this->placeParagraph($paragraph);
            if (null !== $outcome) {
                $result = $outcome;
                break;
            }
        }

        return new LayoutResult(
            $this->lines,
            $this->height,
            new FlowCursor($this->paragraphIndex, $this->itemIndex, $this->carry),
            $result,
        );
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
        $widows = ParagraphFormat::of($paragraph->style)->widows;
        [$placed, $carry] = self::advance($lines, $this->carry);
        $remaining = $this->countRemainingLines($paragraph, $this->itemIndex + $placed, $carry, $widows);
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
        $carry      = $this->carry;
        $lineNumber = 0 === $offset && 0 === $carry ? 0 : 1;
        $y          = $this->y;
        while (null !== ($item = $paragraph->items[$offset] ?? null)) {
            if (ItemKind::ColumnBreak === $item->kind || ItemKind::PageBreak === $item->kind) {
                return new LineRun(
                    $lines,
                    $consumed,
                    ItemKind::PageBreak === $item->kind ? FlowResult::NextPage : FlowResult::BoxFull,
                    $y,
                    $carry,
                );
            }
            // nextLine() is null only past the last item, and the loop stops before that.
            /** @var Line $line */
            $line       = $this->breaker->nextLine($paragraph, $offset, $this->width, $lineNumber, $carry);
            $firstInBox = [] === $this->lines && [] === $lines;
            $baseline   = $y + ($firstInBox ? $this->firstBaseline($line) : $line->lineHeight);
            if (($baseline + $line->descent) > ($this->boxHeight + 1.0e-6)) {
                break;
            }
            $lines[]  = [$line, $baseline];
            $y        = $baseline;
            $offset   += $line->itemsConsumed;
            $consumed += $line->itemsConsumed;
            $carry    = $line->carry;
            ++$lineNumber;
        }

        return new LineRun($lines, $consumed, null, $y, $carry);
    }

    /** @param list<array{0: Line, 1: float}> $lines */
    private function commit(array $lines): void
    {
        [$consumed, $carry] = self::advance($lines, $this->carry);
        $starts =
            0 === $this->itemIndex && 0 === $this->carry
                ? $this->paragraphs[$this->paragraphIndex] ?? null
                : null;
        foreach ($lines as [$line, $baseline]) {
            $this->lines[] = new PlacedLine($line, $baseline, $this->paragraphIndex, $starts);
            $starts        = null;
            $this->y       = $baseline;
            $this->height  = max($this->height, $baseline + $line->descent);
        }
        $this->itemIndex += $consumed;
        $this->carry     = $carry;
    }

    private function countRemainingLines(Paragraph $paragraph, int $offset, int $carry, int $limit): int
    {
        $count = 0;
        while ($count <= $limit) {
            $line = $this->breaker->nextLine($paragraph, $offset, $this->width, 1, $carry);
            if (null === $line) {
                break;
            }
            ++$count;
            $offset += $line->itemsConsumed;
            $carry  = $line->carry;
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
        $line = $this->breaker->nextLine($next, 0, $this->width, 0);
        if (null === $line) {
            return true;
        }
        $baseline = $this->y + ParagraphFormat::of($next->style)->spaceBefore + $line->lineHeight;

        return ($baseline + $line->descent) <= ($this->boxHeight + 1.0e-6);
    }

    /**
     * Places as much of the current paragraph as fits; returns a stop reason, or null to go on.
     */
    private function placeParagraph(Paragraph $paragraph): ?FlowResult
    {
        $style    = $paragraph->style;
        $starting = 0 === $this->itemIndex && 0 === $this->carry;
        $carry    = $this->carry;
        $atTop    = [] === $this->lines;
        $yStart   = $this->y;
        if ($starting && ! $atTop) {
            $this->y += ParagraphFormat::of($style)->spaceBefore;
        }
        $run = $this->breakLines($paragraph);
        if (null !== $run->stop) {
            $this->commit($run->lines);
            $this->skipBreakItem($paragraph);

            return $run->stop;
        }
        if (count($paragraph->items) === ($this->itemIndex + $run->consumed)) {
            return $this->placeWhole($paragraph, $run, $yStart, $carry);
        }
        $keepAll = ! $atTop && $starting && ParagraphFormat::of($style)->keepTogether;

        return $this->placePart(
            $paragraph,
            $keepAll ? [] : $run->lines,
            $starting && ! $atTop ? ParagraphFormat::of($style)->orphans : 0,
            $yStart,
        );
    }

    /**
     * Places the lines of a paragraph that does not fit entirely, as many as its widow and orphan
     * rules let stay here; with none, the box is empty or full.
     *
     * @param list<array{0: Line, 1: float}> $lines
     */
    private function placePart(Paragraph $paragraph, array $lines, int $orphanFloor, float $yStart): FlowResult
    {
        $atTop = [] === $this->lines;
        $lines = $this->applyWidowsOrphans($paragraph, $lines, $orphanFloor);
        if ([] === $lines) {
            $this->y = $yStart;

            return $atTop ? FlowResult::BoxEmpty : FlowResult::BoxFull;
        }
        $this->commit($lines);

        return FlowResult::BoxFull;
    }

    /**
     * Places a paragraph that fits entirely, then moves to the next; one kept with the next goes
     * back when the next cannot start here.
     */
    private function placeWhole(Paragraph $paragraph, LineRun $run, float $yStart, int $carry): ?FlowResult
    {
        $style = $paragraph->style;
        $atTop = [] === $this->lines;
        $this->commit($run->lines);
        $this->y += ParagraphFormat::of($style)->spaceAfter;
        ++$this->paragraphIndex;
        $this->itemIndex = 0;
        $this->carry     = 0;
        if (ParagraphFormat::of($style)->keepWithNext && ! $atTop && ! $this->nextParagraphStarts()) {
            $this->rollBack($paragraph, $run, $yStart, $carry);

            return FlowResult::BoxFull;
        }

        return null;
    }

    /** Removes a committed paragraph again so it moves to the next box with its successor. */
    private function rollBack(Paragraph $paragraph, LineRun $run, float $yStart, int $carry): void
    {
        $this->lines = array_slice($this->lines, offset: 0, length: count($this->lines) - count($run->lines));
        --$this->paragraphIndex;
        $this->itemIndex = count($paragraph->items) - $run->consumed;
        $this->carry     = $carry;
        $this->y         = $yStart;
        $this->height    = 0.0;
        foreach ($this->lines as $placed) {
            $this->height = max($this->height, $placed->baseline + $placed->line->descent);
        }
        unset($paragraph);
    }

    private function skipBreakItem(Paragraph $paragraph): void
    {
        ++$this->itemIndex;
        $this->carry = 0;
        if ($this->itemIndex >= count($paragraph->items)) {
            ++$this->paragraphIndex;
            $this->itemIndex = 0;
        }
    }

    /**
     * Moves the cursor past the empty lines and empty paragraphs it starts at: a line with nothing
     * to show, made by a line break alone. A column or page break stops it, as does any line with
     * text or artwork.
     */
    private function skipEmptyBegin(): void
    {
        while (null !== ($paragraph = $this->paragraphs[$this->paragraphIndex] ?? null)) {
            $item = $paragraph->items[$this->itemIndex] ?? null;
            if (null === $item) {
                ++$this->paragraphIndex;
                $this->itemIndex = 0;
                $this->carry     = 0;
                continue;
            }
            if (ItemKind::ColumnBreak === $item->kind || ItemKind::PageBreak === $item->kind) {
                return;
            }
            $lineNumber = 0 === $this->itemIndex && 0 === $this->carry ? 0 : 1;
            /** @var Line $line nextLine() is null only past the last item, and $item is one. */
            $line = $this->breaker->nextLine($paragraph, $this->itemIndex, $this->width, $lineNumber, $this->carry);
            if ([] !== $line->segments || [] !== $line->inlines) {
                return;
            }
            $this->itemIndex += $line->itemsConsumed;
            $this->carry     = $line->carry;
        }
    }
}
