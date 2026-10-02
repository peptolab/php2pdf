<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\Justification;
use Php2Pdf\Text\Style\TextStyle;

use function array_pop;
use function array_slice;
use function count;
use function preg_match;

/**
 * Sets the items of a line: drops the spaces it ends with, adds the hyphen it breaks at, puts
 * the items in visual order, aligns or justifies them (its word spaces stretched or shrunk within
 * their Justification limits, as the paragraph composer scored them), and merges them into a
 * run per style.
 *
 * @internal
 */
final class LineAssembler
{
    /**
     * Positions the line's items in visual order: alignment, justification and tabs, merging runs
     * per style.
     *
     * @param list<Item>                  $items     in logical order
     * @param array{0: Alignment, 1: int} $direction the line's alignment and the paragraph's bidi level
     * @param array{0: int, 1: int}       $consumed  the whole items the line takes, and the characters
     *                                               placed of the item after them
     */
    public static function line(
        array $items,
        ?Item $hyphen,
        TextStyle $style,
        array $direction,
        float $left,
        float $available,
        array $consumed,
        bool $last,
    ): Line {
        $end = count($items);
        while ($end > 0 && ItemKind::Glue === ($items[$end - 1] ?? null)?->kind) {
            --$end;
        }
        if ($end < count($items)) {
            $items = self::rekernLast(array_slice($items, offset: 0, length: $end), null);
        }
        if (null !== $hyphen && null !== $hyphen->hyphenRun) {
            $items   = self::rekernLast($items, $hyphen->hyphenKerning);
            $items[] = new Item(ItemKind::Box, $hyphen->style, '-', $hyphen->hyphenRun, $hyphen->hyphenWidth);
        }
        if (null !== $hyphen && null === $hyphen->hyphenRun) {
            $items = self::rekernLast(self::withoutTrailingSpace($items), null);
        }
        [$alignment, $paragraphLevel] = $direction;
        $items   = BidiLine::visual($items, $paragraphLevel);
        $natural = 0.0;
        $hasTab  = self::containsTab($items);
        foreach ($items as $item) {
            $natural += $item->width;
        }

        $extras  = ! $hasTab && Alignment::Justify === $alignment ? self::spread($items, $available - $natural) : [];
        $justify = [] !== $extras;
        $shift   = $hasTab || $justify
            ? 0.0
            : match ($alignment) {
                Alignment::Right  => $available - $natural,
                Alignment::Center => ($available - $natural) / 2.0,
                default           => 0.0,
            };

        [$segments, $width, $inlines] = self::segments($items, $style, $extras);
        $metrics = LineMetrics::of($items, $style);

        return new Line(
            $segments,
            $left + $shift,
            $width,
            $metrics['ascent'],
            $metrics['descent'],
            $metrics['capHeight'],
            $metrics['xHeight'],
            $metrics['lineHeight'],
            $style,
            $consumed[0],
            $last,
            $inlines,
            $consumed[1],
        );
    }

    /**
     * Changes the kerning of the line's last glyph: by $change thousandths of an em when a hyphen
     * follows it (the hyphen's width already includes the change), or to none when the line ends
     * at a space it was kerned towards.
     *
     * @param list<Item> $items
     *
     * @return list<Item>
     */
    public static function rekernLast(array $items, ?float $change): array
    {
        $index = count($items) - 1;
        $item  = $items[$index] ?? null;
        $run   = $item?->run;
        if (null === $item || null === $run || ItemKind::Box !== $item->kind || 1 === ($item->level & 1)) {
            return $items;
        }
        $glyphs = $run->glyphs;
        $last   = count($glyphs) - 1;
        $glyph  = $glyphs[$last] ?? null;
        if (null === $glyph || 0.0 === ($change ?? $glyph->kerning)) {
            return $items;
        }
        $kerning       = null === $change ? 0.0 : $glyph->kerning + $change;
        $glyphs[$last] = $glyph->withKerning($kerning);
        $width         = $item->width
        - (null === $change ? ItemFactory::kerningWidth($glyph->kerning, $item->style) : 0.0);
        $items[$index] = new Item(
            ItemKind::Box,
            $item->style,
            $item->text,
            new GlyphRun($glyphs),
            $width,
            level: $item->level,
        );

        return $items;
    }

    /** @param list<Item> $items */
    private static function containsTab(array $items): bool
    {
        foreach ($items as $item) {
            if (ItemKind::Tab === $item->kind) {
                return true;
            }
        }

        return false;
    }

    /**
     * Merges consecutive items of one style into runs, folding justification into the space glyphs
     * and resolving tab positions.
     *
     * @param list<Item>        $items
     * @param array<int, float> $extras the width each word space at an index gains (or loses)
     *
     * @return array{0: list<Segment>, 1: float, 2: list<array{0: float, 1: InlineObject}>}
     */
    private static function segments(array $items, TextStyle $style, array $extras): array
    {
        $segments = [];
        $inlines  = [];
        $tabs     = TabRuler::of($style);
        $x        = 0.0;
        $i        = 0;
        while (null !== ($item = $items[$i] ?? null)) {
            if (ItemKind::Tab === $item->kind) {
                $target = $tabs->target($x, $items, $i + 1);
                $leader = $tabs->leader($x, $target, $item->style);
                if (null !== $leader) {
                    $segments[] = $leader;
                }
                $x = $target;
                ++$i;
                continue;
            }
            if (null !== $item->inline) {
                $inlines[] = [$x, $item->inline];
                $x         += $item->width;
                ++$i;
                continue;
            }
            if (null === $item->run) {
                ++$i;
                continue;
            }
            $runStyle = $item->style;
            $glyphs   = [];
            $width    = 0.0;
            while (
                null !== ($current = $items[$i] ?? null)
                && $current->style === $runStyle
                && null !== $current->run
            ) {
                foreach ($current->run->glyphs as $glyph) {
                    $glyphs[] = $glyph;
                }
                $width += $current->width;
                $extra = $extras[$i] ?? 0.0;
                if (ItemKind::Glue === $current->kind && 0.0 !== $extra) {
                    $space = array_pop($glyphs);
                    if ($space instanceof Glyph) {
                        $scale    = ($runStyle->horizontalScale ?? 100.0) / 100.0;
                        $adjust   = (($extra / $scale) / $runStyle->fontSize()) * 1000.0;
                        $glyphs[] = $space->withKerning($space->kerning + $adjust);
                    }
                    $width += $extra;
                }
                ++$i;
            }
            if ([] !== $glyphs) {
                $segments[] = new Segment($x, $width, $runStyle, new GlyphRun($glyphs));
            }
            $x += $width;
        }

        return [$segments, $x, $inlines];
    }

    /**
     * How a justified line shares its slack among its word spaces: each gains (or, on a line set
     * tight, loses) in proportion to how far its style lets it stretch (or shrink), as the
     * paragraph composer scored the line. None when the line has no space that may change.
     *
     * @param list<Item> $items
     *
     * @return array<int, float> the change to the word space at each index
     */
    private static function spread(array $items, float $missing): array
    {
        $flexibility = [];
        $total       = 0.0;
        foreach ($items as $k => $item) {
            if (ItemKind::Glue !== $item->kind) {
                continue;
            }
            $justification = $item->style->justification;
            $fraction      = $missing >= 0.0
                ? Justification::stretchOf($justification)
                : Justification::shrinkOf($justification);
            $flexibility[$k] = $item->spaceWidth() * $fraction;
            $total           += $flexibility[$k];
        }
        if ($total <= 0.0) {
            return [];
        }
        $extras = [];
        foreach ($flexibility as $k => $flex) {
            $extras[$k] = ($missing * $flex) / $total;
        }

        return $extras;
    }

    /**
     * The items without a fixed-width space at the end, which a line breaking after it drops.
     *
     * @param list<Item> $items
     *
     * @return list<Item>
     */
    private static function withoutTrailingSpace(array $items): array
    {
        $last = $items[count($items) - 1] ?? null;
        if (
            null !== $last
            && ItemKind::Box === $last->kind
            && preg_match('/^[\x{2002}-\x{2006}\x{2008}-\x{200A}]$/u', $last->text) === 1
        ) {
            array_pop($items);
        }

        return $items;
    }
}
