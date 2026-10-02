<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\TextStyle;

use function array_slice;
use function count;
use function end;
use function max;

/**
 * Breaks a paragraph into lines for a given width, one line at a time so a box can stop part
 * way: where each line ends, by the paragraph composer or greedily, with the indents and the
 * alignment it is set with. A word wider than the line is split between characters.
 *
 * @internal
 */
final class LineBreaker
{
    /**
     * Breaks the next line from the paragraph's items starting at $offset; null at the end.
     *
     * @param int $lineNumber 0 for the paragraph's first line, which takes the first-line indent
     */
    public static function nextLine(
        Paragraph $paragraph,
        int $offset,
        float $width,
        int $lineNumber,
        int $carry = 0,
    ): ?Line {
        $style = $paragraph->style;
        $items = $paragraph->items;
        $count = count($items);
        if ($offset >= $count) {
            return null;
        }
        $items = BoxSplit::resume($items, $offset, $carry);
        [$left, $available] = self::indents($style, $width, $lineNumber);
        $start = self::firstItem($items, $offset);
        $end   = (0 === $carry ? $paragraph->plannedEnd($start, $width, $lineNumber) : null)
            ?? GreedyBreaker::lineEnd($items, $style, $start, $available);
        [$items, $consumed] = null === $end->head
            ? [$items, [self::consumed($end, $items, $offset), 0]]
            : BoxSplit::line($items, $end, $offset, $carry);
        $lineItems = array_slice($items, $start, $end->index - $start);
        [$body, $lastLine] = self::alignments($paragraph);
        $last = 0 === $consumed[1] && (($end->index + ($end->forced ? 1 : 0)) >= $count || $end->forced);

        if (ItemKind::Glue === ($items[$end->index] ?? null)?->kind) {
            $lineItems = LineAssembler::rekernLast($lineItems, null);
        }

        return LineAssembler::line(
            $lineItems,
            $end->hyphen,
            $style,
            [$last ? $lastLine : $body, $paragraph->level],
            $left,
            $available,
            $consumed,
            $last,
        );
    }

    /**
     * The paragraph's alignments: for its lines, by default its direction's start edge, and for
     * its last line, which justified text sets with the last-line alignment.
     *
     * @return array{0: Alignment, 1: Alignment}
     */
    private static function alignments(Paragraph $paragraph): array
    {
        $start     = 1 === ($paragraph->level & 1) ? Alignment::Right : Alignment::Left;
        $alignment = $paragraph->style->alignment ?? $start;

        return [
            $alignment,
            Alignment::Justify === $alignment ? $paragraph->style->lastLineAlignment ?? $start : $alignment,
        ];
    }

    /**
     * How many items from $offset the line takes: up to its end, and a forced line break with it.
     * A column or page break stays for the layout, which stops the box there.
     *
     * @param list<Item> $items
     */
    private static function consumed(LineEnd $end, array $items, int $offset): int
    {
        $lineBreak = $end->forced && ItemKind::LineBreak === ($items[$end->index] ?? null)?->kind;

        return $end->index - $offset + ($lineBreak ? 1 : 0);
    }

    /**
     * Where a line starts: past the glue left over from the break before it.
     *
     * @param list<Item> $items
     */
    private static function firstItem(array $items, int $offset): int
    {
        $start = $offset;
        while (ItemKind::Glue === ($items[$start] ?? null)?->kind) {
            ++$start;
        }

        return $start;
    }

    /**
     * Where a line starts from the frame's left edge, and the width it has: the left indent, and
     * on the paragraph's first line the first-line indent, then the right indent.
     *
     * @return array{0: float, 1: float}
     */
    private static function indents(TextStyle $style, float $width, int $lineNumber): array
    {
        $left = ($style->leftIndent ?? 0.0) + (0 === $lineNumber ? $style->firstLineIndent ?? 0.0 : 0.0);

        return [$left, max(1.0, $width - $left - ($style->rightIndent ?? 0.0))];
    }
}
