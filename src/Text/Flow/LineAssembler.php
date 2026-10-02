<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\TextStyle;

use function array_pop;
use function array_slice;
use function count;
use function preg_match;

/**
 * Sets the items of a line: drops the spaces it ends with, adds the hyphen it breaks at, puts
 * the items in visual order, aligns or justifies them, and merges them into a run per style.
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
        $glues   = 0;
        $hasTab  = self::containsTab($items);
        foreach ($items as $item) {
            $natural += $item->width;
            if (ItemKind::Glue === $item->kind) {
                ++$glues;
            }
        }

        $justify      = ! $hasTab && Alignment::Justify === $alignment && $glues > 0;
        $extraPerGlue = $justify ? ($available - $natural) / $glues : 0.0;
        $shift        = $hasTab || $justify
            ? 0.0
            : match ($alignment) {
                Alignment::Right  => $available - $natural,
                Alignment::Center => ($available - $natural) / 2.0,
                default           => 0.0,
            };

        [$segments, $width, $inlines] = self::segments($items, $style, $extraPerGlue);
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
        - (null === $change ? Itemiser::kerningWidth($glyph->kerning, $item->style) : 0.0);
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
     * @param list<Item> $items
     *
     * @return array{0: list<Segment>, 1: float, 2: list<array{0: float, 1: InlineObject}>}
     */
    private static function segments(array $items, TextStyle $style, float $extraPerGlue): array
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
            // Gather a run of items sharing this item's style.
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
                if (ItemKind::Glue === $current->kind && 0.0 !== $extraPerGlue) {
                    $space = array_pop($glyphs);
                    if ($space instanceof Glyph) {
                        $scale    = ($runStyle->horizontalScale ?? 100.0) / 100.0;
                        $adjust   = (($extraPerGlue / $scale) / $runStyle->fontSize()) * 1000.0;
                        $glyphs[] = $space->withKerning($space->kerning + $adjust);
                    }
                    $width += $extraPerGlue;
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
