<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Text\Style\TextStyle;

use function count;

/**
 * Greedy line breaking: a line takes as many items as fit, ending at the last break before the
 * first that does not.
 *
 * @internal
 */
final class GreedyBreaker
{
    /**
     * Finds where the line ends. A box too wide for any line is split between characters, its
     * head ending the line.
     *
     * @param list<Item> $items
     */
    public static function lineEnd(array $items, TextStyle $style, int $start, float $available): LineEnd
    {
        $count     = count($items);
        $x         = 0.0;
        $lastBreak = -1;
        $tabs      = TabRuler::of($style);
        for ($i = $start; null !== ($item = $items[$i] ?? null); ++$i) {
            // The commonest items first: a box that fits, then glue; the kind is read once.
            $kind = $item->kind;
            if (ItemKind::Box === $kind && ($x + $item->width) <= ($available + 1.0e-6)) {
                $x += $item->width;
                continue;
            }
            if (ItemKind::Glue === $kind) {
                $lastBreak = $i;
                $x         += $item->width;
                continue;
            }
            // Item::isForcedBreak(), inlined.
            if (ItemKind::LineBreak === $kind || ItemKind::ColumnBreak === $kind || ItemKind::PageBreak === $kind) {
                return new LineEnd($i, null, true);
            }
            if (ItemKind::Tab === $kind) {
                $x = $tabs->target($x, $items, $i + 1);
                continue;
            }
            if (ItemKind::Penalty === $kind) {
                if (($x + $item->hyphenWidth) <= $available) {
                    $lastBreak = $i;
                }
                continue;
            }
            if (($x + $item->width) <= ($available + 1.0e-6)) {
                $x += $item->width;
                continue;
            }
            $breakItem = $lastBreak >= 0 ? $items[$lastBreak] ?? null : null;
            if (null === $breakItem && ItemKind::Inline === $item->kind) {
                // Artwork wider than the line goes on a line of its own, overhanging.
                return new LineEnd($i > $start ? $i : $i + 1, null, false);
            }
            if (null !== $breakItem) {
                return new LineEnd($lastBreak, ItemKind::Penalty === $breakItem->kind ? $breakItem : null, false);
            }
            return new LineEnd($i + 1, null, false, BoxSplit::head($item, $available - $x));
        }

        return new LineEnd($count, null, false);
    }
}
