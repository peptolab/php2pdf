<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\TextStyle;

use function abs;
use function array_merge;
use function array_pop;
use function array_slice;
use function count;
use function max;
use function min;
use function preg_match;

/**
 * Sets the items of a line: drops the spaces it ends with, adds the hyphen it breaks at, puts
 * the items in visual order, aligns or justifies them (its word spaces stretched or shrunk within
 * their Justification limits, as the paragraph composer scored them, then letter spacing and glyph scaling where the style
 * allows them), and merges them into a run per style.
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

        $spread = ! $hasTab && Alignment::Justify === $alignment
            ? self::spread($items, $available - $natural)
            : new LineSpread();
        $justify = $spread->justifies();
        $shift   = $hasTab || $justify
            ? 0.0
            : match ($alignment) {
                Alignment::Right  => $available - $natural,
                Alignment::Center => ($available - $natural) / 2.0,
                default           => 0.0,
            };

        [$segments, $width, $inlines] = self::segments($items, $style, $spread);
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

    /**
     * Takes a line's slack in word spaces, then letter spacing, then glyph scaling, each as far as
     * it may, and the rest in word spaces (or, without any, letter spacing) past their limits.
     *
     * @param array{0: array<int, float>, 1: float}           $words      how far each word space may change, and in all
     * @param array{0: array<int, float>, 1: float, 2: ?int} $letters    how far the space after each item's glyphs may
     *                                                                    change, in all, and the item with the last glyph
     * @param float                                            $textWidth the width of the line's glyphs, which scaling changes
     */
    private static function byPriority(
        float $missing,
        array $words,
        array $letters,
        float $textWidth,
        float $glyphRange,
    ): LineSpread {
        [$wordFlex, $wordTotal] = $words;
        [$gapFlex, $gapTotal, $last] = $letters;
        $sign   = $missing >= 0.0 ? 1.0 : -1.0;
        $amount = $sign * $missing;
        $word   = min($amount, $wordTotal);
        $letter = min($amount - $word, $gapTotal);
        $rest   = $amount - $word - $letter;
        $scale  = 1.0;
        $base   = $textWidth + ($sign * ($word + $letter));
        if ($rest > 0.0 && $glyphRange > 0.0 && $base > 0.0) {
            $scale = $sign > 0.0
                ? min(1.0 + $glyphRange, ($base + $rest) / $base)
                : max(1.0 - $glyphRange, ($base - $rest) / $base);
            $rest = ($rest - abs(($base * $scale) - $base)) / $scale;
        }
        if ($rest > 0.0 && $wordTotal > 0.0) {
            $word += $rest;
        }
        if ($rest > 0.0 && $wordTotal <= 0.0) {
            $letter += $rest;
        }

        return new LineSpread(
            $wordTotal > 0.0 ? self::share($wordFlex, $sign * $word, $wordTotal) : [],
            $gapTotal > 0.0 ? self::share($gapFlex, $sign * $letter, $gapTotal) : [],
            $scale,
            $last,
        );
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
     * An item's glyphs and width with its justification: the change to a word space on the space
     * glyph, and the letter spacing after each glyph but the line's last, as kerning.
     *
     * @return array{0: list<Glyph>, 1: float} the glyphs, and the width before scaling
     */
    private static function justified(Item $item, int $index, LineSpread $spread): array
    {
        $glyphs = null === $item->run ? [] : $item->run->glyphs;
        $width  = $item->width;
        $word   = ItemKind::Glue === $item->kind ? $spread->words[$index] ?? 0.0 : 0.0;
        $letter = $spread->letters[$index] ?? 0.0;
        if (0.0 === $word && 0.0 === $letter) {
            return [$glyphs, $width];
        }
        $format = $item->format;
        $toEm   = 1000.0 / (($format->horizontalScale / 100.0) * $format->size);
        $last   = $spread->last === $index ? count($glyphs) - 1 : -1;
        foreach ($glyphs as $k => $glyph) {
            $after = ($k === $last ? 0.0 : $letter) + ($k === (count($glyphs) - 1) ? $word : 0.0);
            if (0.0 === $after) {
                continue;
            }
            $glyphs[$k] = $glyph->withKerning($glyph->kerning + ($after * $toEm));
            $width      += $after;
        }

        return [$glyphs, $width];
    }

    /**
     * Merges consecutive items of one style into runs, folding justification into the glyphs (word
     * spaces and letter spacing as kerning, glyph scaling as the runs' scale) and resolving tab
     * positions.
     *
     * @param list<Item> $items
     *
     * @return array{0: list<Segment>, 1: float, 2: list<array{0: float, 1: InlineObject}>}
     */
    private static function segments(array $items, TextStyle $style, LineSpread $spread): array
    {
        $segments = [];
        $inlines  = [];
        $tabs     = TabRuler::of($style);
        $x        = 0.0;
        $i        = 0;
        // Most lines are not justified, and most justified lines change their word spaces only:
        // their items' glyphs are taken as they are, or with only a word space's last glyph changed.
        $wordsOnly = [] === $spread->letters;
        $plain     = $wordsOnly && [] === $spread->words;
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
            $lists    = [];
            $width    = 0.0;
            while (null !== ($current = $items[$i] ?? null)) {
                // A break opportunity the line does not break at (a hyphenation point, or one after
                // a hard hyphen) shows nothing and takes no space: the run carries on past it.
                if (
                    ItemKind::Penalty === $current->kind
                    && null === $current->run
                    && null === $current->inline
                    && 0.0 === $current->width
                ) {
                    ++$i;
                    continue;
                }
                if ($current->style !== $runStyle || null === $current->run) {
                    break;
                }
                if ($plain) {
                    $lists[] = $current->run->glyphs;
                    $width   += $current->width;
                    ++$i;
                    continue;
                }
                [$lists[], $itemWidth] = $wordsOnly
                    ? self::wordSpaced($current, $i, $spread)
                    : self::justified($current, $i, $spread);
                $width += $itemWidth;
                ++$i;
            }
            $glyphs = 1 === count($lists) ? $lists[0] : array_merge(...$lists);
            if ([] !== $glyphs) {
                $segments[] = new Segment(
                    $x,
                    $width * $spread->scale,
                    $runStyle,
                    new GlyphRun($glyphs),
                    $spread->scale,
                );
            }
            $x += $width * $spread->scale;
        }

        return [$segments, $x, $inlines];
    }

    /**
     * An amount shared in proportion to each part's flexibility.
     *
     * @param array<int, float> $flexibility
     *
     * @return array<int, float>
     */
    private static function share(array $flexibility, float $amount, float $total): array
    {
        $shares = [];
        foreach ($flexibility as $k => $flex) {
            $shares[$k] = ($amount * $flex) / $total;
        }

        return $shares;
    }

    /**
     * How a justified line takes its slack: its word spaces first, each in proportion to how far
     * its style lets it stretch (or, on a line set tight, shrink), as far as they may; then the
     * letter spacing of its glyphs, likewise; then the width of its glyphs, as far as the style
     * that allows least lets them change. Whatever is left goes to the word spaces past their
     * limits. With no letter spacing or glyph scaling allowed, the word spaces take it all, in
     * proportion, as the paragraph composer scored the line. Nothing changes on a line with
     * nothing that may.
     *
     * @param list<Item> $items
     */
    private static function spread(array $items, float $missing): LineSpread
    {
        $stretching = $missing >= 0.0;
        $words      = [];
        $wordTotal  = 0.0;
        $gaps       = [];
        $gapTotal   = 0.0;
        $last       = null;
        $textWidth  = 0.0;
        $glyphRange = null;
        foreach ($items as $k => $item) {
            $format = $item->format;
            if (ItemKind::Glue === $item->kind) {
                $words[$k] = $item->spaceWidth() * ($stretching ? $format->stretch : $format->shrink);
                $wordTotal += $words[$k];
            }
            $count = null === $item->run ? 0 : count($item->run->glyphs);
            if (0 === $count) {
                continue;
            }
            // As Item::letterStretch() and letterShrink(), asked of the item only when its style
            // allows any letter spacing (most do not).
            $letterFlex = $stretching ? $format->letterStretch : $format->letterShrink;
            $gaps[$k]   = 0.0;
            if (0.0 !== $letterFlex) {
                $gaps[$k] = $stretching ? $item->letterStretch() : $item->letterShrink();
            }
            $gapTotal   += $count * $gaps[$k];
            $last       = $k;
            $textWidth  += $item->width;
            $range      = $stretching ? $format->glyphStretch : $format->glyphShrink;
            $glyphRange = null === $glyphRange ? $range : min($glyphRange, $range);
        }
        $gapTotal   -= null === $last ? 0.0 : $gaps[$last] ?? 0.0;
        $glyphRange ??= 0.0;
        if ($gapTotal <= 0.0 && $glyphRange <= 0.0) {
            return $wordTotal <= 0.0 ? new LineSpread() : new LineSpread(self::share($words, $missing, $wordTotal));
        }

        return self::byPriority($missing, [$words, $wordTotal], [$gaps, $gapTotal, $last], $textWidth, $glyphRange);
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

    /**
     * An item's glyphs and width on a line whose justification changes its word spaces only: as
     * justified() gives them, without walking the glyphs. A word space's change goes on its last
     * glyph; any other item is unchanged.
     *
     * @return array{0: list<Glyph>, 1: float} the glyphs, and the width before scaling
     */
    private static function wordSpaced(Item $item, int $index, LineSpread $spread): array
    {
        $glyphs = null === $item->run ? [] : $item->run->glyphs;
        if (ItemKind::Glue !== $item->kind) {
            return [$glyphs, $item->width];
        }
        $word  = $spread->words[$index] ?? 0.0;
        $last  = count($glyphs) - 1;
        $glyph = $glyphs[$last] ?? null;
        if (0.0 === $word || null === $glyph) {
            return [$glyphs, $item->width];
        }
        $format        = $item->format;
        $toEm          = 1000.0 / (($format->horizontalScale / 100.0) * $format->size);
        $glyphs[$last] = $glyph->withKerning($glyph->kerning + ($word * $toEm));

        return [$glyphs, $item->width + $word];
    }
}
