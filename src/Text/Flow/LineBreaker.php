<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\TabAlignment;
use Php2Pdf\Text\Style\TabStop;
use Php2Pdf\Text\Style\TextStyle;

use function array_pop;
use function array_slice;
use function ceil;
use function count;
use function end;
use function floor;
use function implode;
use function intdiv;
use function max;
use function mb_str_split;
use function preg_match;
use function strpos;
use function substr;
use function usort;

/**
 * Greedy line breaking with alignment, justification, tabs and indents. Breaks the items of one
 * paragraph into lines for a given width, one line at a time so a box can stop part way. A word
 * wider than the line is split between characters.
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
    public static function nextLine(Paragraph $paragraph, int $offset, float $width, int $lineNumber): ?Line
    {
        $style = $paragraph->style;
        $items = $paragraph->items;
        $count = count($items);
        if ($offset >= $count) {
            return null;
        }
        $left      = ($style->leftIndent ?? 0.0) + (0 === $lineNumber ? $style->firstLineIndent ?? 0.0 : 0.0);
        $available = max(1.0, $width - $left - ($style->rightIndent ?? 0.0));

        // Glue left over from the previous break does not start a line.
        $start = $offset;
        while (ItemKind::Glue === ($items[$start] ?? null)?->kind) {
            ++$start;
        }
        $end = $paragraph->plannedEnd($start, $width, 0 === $lineNumber) ?? self::measure(
            $paragraph,
            $start,
            $available,
        );
        if ($end->splitAt >= 0) {
            $items = $paragraph->items;
            $count = count($items);
        }
        $lineItems = array_slice($items, $start, $end->index - $start);
        $consumed  = self::consumed($end, $items, $offset);
        $last      = ($start + $end->index - $start + ($end->forced ? 1 : 0)) >= $count || $end->forced;

        $start     = 1 === ($paragraph->level & 1) ? Alignment::Right : Alignment::Left;
        $alignment = $style->alignment ?? $start;
        if ($last && Alignment::Justify === $alignment) {
            $alignment = $style->lastLineAlignment ?? $start;
        }

        if (ItemKind::Glue === ($items[$end->index] ?? null)?->kind) {
            $lineItems = self::rekernLast($lineItems, null);
        }

        return self::compose(
            $lineItems,
            $end->hyphen,
            $style,
            [$alignment, $paragraph->level],
            $left,
            $available,
            $consumed,
            $last,
        );
    }

    /**
     * Positions the line's items in visual order: alignment, justification and tabs, merging runs
     * per style.
     *
     * @param list<Item>                  $items     in logical order
     * @param array{0: Alignment, 1: int} $direction the line's alignment and the paragraph's bidi level
     */
    private static function compose(
        array $items,
        ?Item $hyphen,
        TextStyle $style,
        array $direction,
        float $left,
        float $available,
        int $consumed,
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
        $metrics = self::metrics($items, $style);

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
            $consumed,
            $last,
            $inlines,
        );
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
     * The leader filling a tab's gap from $from to $to: whole repeats of its text on a grid of its
     * width from the line's start, or null when none fits.
     */
    private static function leader(float $from, float $to, ?string $text, TextStyle $style): ?Segment
    {
        if (null === $text) {
            return null;
        }
        $unit = Itemiser::box($text, $style);
        if (null === $unit->run || $unit->width <= 0.0) {
            return null;
        }
        $start   = ceil(($from / $unit->width) - 1.0e-6) * $unit->width;
        $repeats = (int) floor((($to - $start) / $unit->width) + 1.0e-6);
        if ($repeats < 1) {
            return null;
        }
        $glyphs = [];
        for ($k = 0; $k < $repeats; ++$k) {
            foreach ($unit->run->glyphs as $glyph) {
                $glyphs[] = $glyph;
            }
        }

        return new Segment($start, $repeats * $unit->width, $style, new GlyphRun($glyphs));
    }

    /**
     * Finds where the line ends. Splits an over-wide box inside the paragraph when needed.
     */
    private static function measure(Paragraph $paragraph, int $start, float $available): LineEnd
    {
        $items     = $paragraph->items;
        $count     = count($items);
        $x         = 0.0;
        $lastBreak = -1;
        $tabs      = self::tabPositions($paragraph->style);
        for ($i = $start; null !== ($item = $items[$i] ?? null); ++$i) {
            if ($item->isForcedBreak()) {
                return new LineEnd($i, null, true);
            }
            if (ItemKind::Tab === $item->kind) {
                $x = self::tabTarget($x, $items, $i + 1, $tabs, $paragraph->style);
                continue;
            }
            if (ItemKind::Penalty === $item->kind) {
                if (($x + $item->hyphenWidth) <= $available) {
                    $lastBreak = $i;
                }
                continue;
            }
            if (ItemKind::Glue === $item->kind) {
                $lastBreak = $i;
                $x         += $item->width;
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
            // A single box wider than the line: split it between characters.
            $pieces = self::splitBox($item, $available - $x);
            $paragraph->split($i, $pieces);

            return new LineEnd($i + 1, null, false, $i);
        }

        return new LineEnd($count, null, false);
    }

    /**
     * The line's vertical metrics from every font and artwork on it. Artwork rising above the text
     * pushes the line down by the difference, as auto leading does.
     *
     * @param list<Item> $items
     *
     * @return array{ascent: float, descent: float, capHeight: float, xHeight: float, lineHeight: float}
     */
    private static function metrics(array $items, TextStyle $style): array
    {
        $font    = StyleResolver::fontOf($style);
        $size    = $style->fontSize();
        $metrics = [
            'ascent'     => $font->ascender($size),
            'descent'    => -$font->descender($size),
            'capHeight'  => $font->capHeight($size),
            'xHeight'    => $font->xHeight($size),
            'lineHeight' => $style->lineHeight(),
        ];
        $measured  = null;
        $artTop    = 0.0;
        $artBottom = 0.0;
        foreach ($items as $item) {
            if (null !== $item->inline) {
                $artTop    = max($artTop, $item->inline->height + $item->inline->raise);
                $artBottom = max($artBottom, -$item->inline->raise);
                continue;
            }
            if (ItemKind::Box !== $item->kind || $item->style === $measured) {
                continue;
            }
            $measured              = $item->style;
            $f                     = StyleResolver::fontOf($item->style);
            $s                     = $item->style->fontSize();
            $rise                  = $item->style->rise ?? 0.0;
            $metrics['ascent']     = max($metrics['ascent'], $f->ascender($s) + $rise);
            $metrics['descent']    = max($metrics['descent'], -$f->descender($s) - $rise);
            $metrics['lineHeight'] = max($metrics['lineHeight'], $item->style->lineHeight());
        }

        $above                 = max(0.0, $artTop - $metrics['ascent']);
        $metrics['ascent']     += $above;
        $metrics['lineHeight'] += $above;
        $metrics['descent']    = max($metrics['descent'], $artBottom);

        return $metrics;
    }

    /** @param list<TabStop> $tabs */
    private static function nextTab(float $x, array $tabs, TextStyle $style): float
    {
        foreach ($tabs as $tab) {
            if ($tab->position > ($x + 1.0e-6)) {
                return $tab->position;
            }
        }
        $step = max(1.0, $style->defaultTabWidth ?? 36.0);

        return (floor(($x / $step) + 1.0e-6) + 1.0) * $step;
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
    private static function rekernLast(array $items, ?float $change): array
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
        $tabs     = self::tabPositions($style);
        $x        = 0.0;
        $i        = 0;
        while (null !== ($item = $items[$i] ?? null)) {
            if (ItemKind::Tab === $item->kind) {
                $target = self::tabTarget($x, $items, $i + 1, $tabs, $style);
                $leader = self::leader($x, $target, self::stopAfter($x, $tabs)?->leader, $item->style);
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
     * Splits a box so its first piece fits $width (at least one character).
     *
     * @return list<Item>
     */
    private static function splitBox(Item $item, float $width): array
    {
        $chars = mb_str_split($item->text, length: 1, encoding: 'UTF-8');
        $n     = count($chars);
        $lo    = 1;
        $hi    = max(1, $n - 1);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, num2: 2);
            $box = Itemiser::box(implode('', array_slice($chars, offset: 0, length: $mid)), $item->style, $item->level);
            if ($box->width > $width) {
                $hi = $mid - 1;
                continue;
            }
            $lo = $mid;
        }
        $head = Itemiser::box(implode('', array_slice($chars, offset: 0, length: $lo)), $item->style, $item->level);
        $tail = implode('', array_slice($chars, $lo));

        return '' === $tail ? [$head] : [$head, Itemiser::box($tail, $item->style, $item->level)];
    }

    /**
     * The first tab stop after $x, or null past the last one (default tabs follow).
     *
     * @param list<TabStop> $tabs
     */
    private static function stopAfter(float $x, array $tabs): ?TabStop
    {
        foreach ($tabs as $tab) {
            if ($tab->position > ($x + 1.0e-6)) {
                return $tab;
            }
        }

        return null;
    }

    /** @return list<TabStop> */
    private static function tabPositions(TextStyle $style): array
    {
        $tabs = $style->tabs ?? [];
        usort($tabs, static fn(TabStop $a, TabStop $b): int => $a->position <=> $b->position);

        return $tabs;
    }

    /**
     * The x position after a tab, accounting for right, centre and decimal alignment of the text
     * that follows it up to the next tab.
     *
     * @param list<Item>    $items
     * @param list<TabStop> $tabs
     */
    private static function tabTarget(float $x, array $items, int $from, array $tabs, TextStyle $style): float
    {
        $stop = self::stopAfter($x, $tabs);
        if (null === $stop) {
            return self::nextTab($x, $tabs, $style);
        }
        $segmentWidth = 0.0;
        $decimalWidth = null;
        for ($i = $from; null !== ($item = $items[$i] ?? null) && ItemKind::Tab !== $item->kind; ++$i) {
            $pos =
                TabAlignment::Decimal === $stop->alignment && null === $decimalWidth && ItemKind::Box === $item->kind
                    ? strpos($item->text, $stop->decimalSeparator)
                    : false;
            if (false === $pos) {
                $segmentWidth += $item->width;
                continue;
            }
            $prefix       = Itemiser::box(substr($item->text, offset: 0, length: $pos), $item->style);
            $decimalWidth = $segmentWidth + $prefix->width;
            $segmentWidth += $item->width;
        }

        return match ($stop->alignment) {
            TabAlignment::Left    => $stop->position,
            TabAlignment::Right   => max($x, $stop->position - $segmentWidth),
            TabAlignment::Center  => max($x, $stop->position - ($segmentWidth / 2.0)),
            TabAlignment::Decimal => max($x, $stop->position - ($decimalWidth ?? $segmentWidth)),
        };
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
