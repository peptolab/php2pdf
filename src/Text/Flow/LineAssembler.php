<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Canvas\RunPieces;
use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Font\PdfFontInterface;
use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\CharFormat;
use Php2Pdf\Text\Style\TextStyle;
use WeakMap;

use function abs;
use function array_column;
use function array_key_last;
use function array_merge;
use function array_pop;
use function array_slice;
use function count;
use function in_array;
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
     * The glyph wordSpaced() last stretched, the kerning it gave it, and the glyph stretched.
     *
     * @var array{0: Glyph, 1: float, 2: Glyph}|null
     */
    private static ?array $stretched = null;

    /**
     * Whether a run shows a font's word space glyph, by run: a run is shown in one font, and most
     * are words shown on many lines.
     *
     * @var WeakMap<GlyphRun, bool>|null
     */
    private static ?WeakMap $spaced = null;

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
        $natural = 0.0;
        $hasTab  = false;
        $bidi    = 0 !== $paragraphLevel;
        foreach ($items as $item) {
            $natural += $item->width;
            $hasTab  = $hasTab || ItemKind::Tab === $item->kind;
            $bidi    = $bidi || 0 !== $item->level;
        }
        // Most lines are left to right at level 0, in visual order already. The others are
        // measured again in visual order, which the width adds up in.
        if ($bidi) {
            $items   = BidiLine::visual($items, $paragraphLevel);
            $natural = 0.0;
            foreach ($items as $item) {
                $natural += $item->width;
            }
        }

        $spread = ! $hasTab && Alignment::Justify === $alignment
            ? self::spread($items, $available - $natural)
            : new LineSpread();
        $justify = $spread->justifies;
        $shift   = $hasTab || $justify
            ? 0.0
            : match ($alignment) {
                Alignment::Right  => $available - $natural,
                Alignment::Center => ($available - $natural) / 2.0,
                default           => 0.0,
            };

        [$segments, $width, $inlines, $styles] = self::segments($items, $style, $spread);
        $metrics = LineMetrics::ofStyles($styles, $inlines, $style);

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
     * How a justified line without word spaces, whose styles let nothing else change, takes its
     * slack: evenly between its glyphs, all but the last, as a line of Chinese or Japanese is
     * justified. A line set tight, or of one glyph, does not change.
     *
     * @param list<Item> $items
     */
    private static function letterSpread(array $items, float $missing): LineSpread
    {
        $letters = [];
        $glyphs  = 0;
        foreach ($items as $k => $item) {
            $count = null === $item->run ? 0 : count($item->run->glyphs);
            if (0 < $count) {
                $letters[$k] = 0.0;
                $glyphs      += $count;
            }
        }
        if ($missing <= 0.0 || $glyphs < 2) {
            return new LineSpread();
        }
        $each = $missing / ($glyphs - 1);
        foreach ($letters as $k => $_) {
            $letters[$k] = $each;
        }

        return new LineSpread(
            letters: $letters,
            last: array_key_last($letters),
        );
    }

    /**
     * Whether a run carries on past a penalty: a break opportunity the line does not break at (a
     * hyphenation point, or one after a hard hyphen), which shows nothing and takes no space.
     */
    private static function passesOver(Item $item): bool
    {
        return null === $item->run && null === $item->inline && 0.0 === $item->width;
    }

    /**
     * The segment of the items of one style, $runStyle, from $items[$i] on, on a line
     * justification does not change: as run() gives it, their glyphs taken as they are.
     *
     * @param list<Item> $items
     *
     * @return array{0: ?Segment, 1: float, 2: int, 3: bool}
     */
    private static function plainRun(array $items, int $i, TextStyle $runStyle, float $x): array
    {
        $lists  = [];
        $pieces = [];
        $width  = 0.0;
        $boxed  = false;
        while (null !== ($current = $items[$i] ?? null)) {
            if (ItemKind::Penalty === $current->kind && self::passesOver($current)) {
                ++$i;
                continue;
            }
            if ($current->style !== $runStyle || null === $current->run) {
                break;
            }
            $boxed    = $boxed || ItemKind::Box === $current->kind;
            $lists[]  = $current->run->glyphs;
            $pieces[] = $current->run;
            $width    += $current->width;
            ++$i;
        }

        $glyphs  = 1 === count($lists) ? $lists[0] : array_merge(...$lists);
        $segment = [] === $glyphs
            ? null
            : new Segment($x, $width, $runStyle, new GlyphRun($glyphs), 1.0, new RunPieces($pieces));

        return [$segment, $width, $i, $boxed];
    }

    /**
     * The segment of the items of one style, $runStyle, from $items[$i] on, as segments() merges
     * them: the segment (none when they have no glyphs), its width, the index of the item after
     * it, and whether any of its items is a box, whose style the line's metrics take. On a line
     * whose justification changes its word spaces only, they are shown with word spacing when
     * they are $space, the glyph the font's word spacing moves, stretched alike, and no other item
     * shows it; otherwise, and without $space, they are kerned.
     *
     * @param list<Item> $items
     *
     * @return array{0: ?Segment, 1: float, 2: int, 3: bool}
     */
    private static function run(
        array $items,
        int $i,
        TextStyle $runStyle,
        float $x,
        LineSpread $spread,
        ?int $space,
    ): array {
        $start    = $i;
        $lists    = [];
        $pieces   = [];
        $kernings = [];
        $width    = 0.0;
        $boxed    = false;
        // Most lines are not justified, and most justified lines change their word spaces only:
        // their items' glyphs are taken as they are, or with only a word space's last glyph changed.
        $wordsOnly = [] === $spread->letters;
        $word      = null;
        while (null !== ($current = $items[$i] ?? null)) {
            if (ItemKind::Penalty === $current->kind && self::passesOver($current)) {
                ++$i;
                continue;
            }
            if ($current->style !== $runStyle || null === $current->run) {
                break;
            }
            if (ItemKind::Box === $current->kind) {
                $boxed = true;
            }
            $index = $i++;
            if (! $wordsOnly) {
                // Letter spacing changes every glyph: the segment is drawn from its glyphs.
                [$lists[], $itemWidth] = self::justified($current, $index, $spread);
                $width += $itemWidth;
                continue;
            }
            // Only word spaces change, if any; any other item is taken as it is, as wordSpaced()
            // would.
            if ([] === $spread->words || ItemKind::Glue !== $current->kind) {
                if (null !== $space && self::shows($current->run, $space)) {
                    return self::run($items, $start, $runStyle, $x, $spread, null);
                }
                $lists[]  = $current->run->glyphs;
                $pieces[] = $current->run;
                $width    += $current->width;
                continue;
            }
            if (null !== $space) {
                $word = self::spacedAlike($current->run, $space, $spread->words[$index] ?? 0.0, $word);
                if (null === $word) {
                    return self::run($items, $start, $runStyle, $x, $spread, null);
                }
                $lists[]  = $current->run->glyphs;
                $pieces[] = $current->run;
                $width    += $current->width + $word;
                continue;
            }
            [$lists[], $itemWidth, $kerning] = self::wordSpaced($current, $index, $spread);
            if (null !== $kerning) {
                $kernings[count($pieces)] = $kerning;
            }
            $pieces[] = $current->run;
            $width    += $itemWidth;
        }
        $width   *= $spread->scale;
        $segment = self::segment($lists, $x, $width, $runStyle, $spread->scale, new RunPieces(
            $pieces,
            $kernings,
            self::wordSpace($word, $runStyle),
        ));

        return [$segment, $width, $i, $boxed];
    }

    /**
     * A segment of the glyphs of its pieces' runs, as run() gives them; none without glyphs.
     *
     * @param list<list<Glyph>> $lists
     */
    private static function segment(
        array $lists,
        float $x,
        float $width,
        TextStyle $style,
        float $scale,
        RunPieces $pieces,
    ): ?Segment {
        $glyphs = 1 === count($lists) ? $lists[0] : array_merge(...$lists);

        return [] === $glyphs ? null : new Segment($x, $width, $style, new GlyphRun($glyphs), $scale, $pieces);
    }

    /**
     * Merges consecutive items of one style into runs, folding justification into the glyphs (word
     * spaces and letter spacing as kerning, glyph scaling as the runs' scale) and resolving tab
     * positions.
     *
     * @param list<Item> $items
     *
     * @return array{0: list<Segment>, 1: float, 2: list<array{0: float, 1: InlineObject}>, 3: list<TextStyle>}
     *         the segments, the line's width, its artwork, and the styles of its boxes for its metrics
     */
    private static function segments(array $items, TextStyle $style, LineSpread $spread): array
    {
        $segments = [];
        $inlines  = [];
        $styles   = [];
        $measured = null;
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
                if (ItemKind::Box === $item->kind && $item->style !== $measured) {
                    $measured = $item->style;
                    $styles[] = $measured;
                }
                ++$i;
                continue;
            }
            [$segment, $width, $i, $boxed] = $spread->justifies
                ? self::run($items, $i, $item->style, $x, $spread, self::wordSpaceGlyph($item->style, $spread))
                : self::plainRun($items, $i, $item->style, $x);
            if (null !== $segment) {
                $segments[] = $segment;
            }
            if ($boxed && $item->style !== $measured) {
                $measured = $item->style;
                $styles[] = $measured;
            }
            $x += $width;
        }

        return [$segments, $x, $inlines, $styles];
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

    /** Whether a run shows the glyph, remembered for the run. */
    private static function shows(GlyphRun $run, int $glyph): bool
    {
        self::$spaced ??= new WeakMap();
        $shows        = self::$spaced[$run] ?? null;
        if (null === $shows) {
            $shows              = in_array($glyph, array_column($run->glyphs, 'id'), strict: true);
            self::$spaced[$run] = $shows;
        }

        return $shows;
    }

    /**
     * The change to a word space shown with word spacing: its own, $change, when its glyph (a word
     * space is one, however many spaces it stands for) is the one the word spacing moves, $space,
     * and it changes as the segment's others do, by $word (null for the first); null when not.
     */
    private static function spacedAlike(GlyphRun $run, int $space, float $change, ?float $word): ?float
    {
        if ($space !== ($run->glyphs[0] ?? null)?->id || (null !== $word && $change !== $word)) {
            return null;
        }

        return $change;
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
        $words = self::wordSpread($items, $missing);
        if (null !== $words) {
            return $words;
        }
        $stretching = $missing >= 0.0;
        $words      = [];
        $wordTotal  = 0.0;
        $gaps       = [];
        $gapTotal   = 0.0;
        $last       = null;
        $textWidth  = 0.0;
        $glyphRange = null;
        // A line's word spaces are mostly one item, shared: its space width is asked once.
        $glue  = null;
        $space = 0.0;
        foreach ($items as $k => $item) {
            $format = $item->format;
            if (ItemKind::Glue === $item->kind) {
                if ($item !== $glue) {
                    $glue  = $item;
                    $space = $item->spaceWidth();
                }
                $words[$k] = $space * ($stretching ? $format->stretch : $format->shrink);
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
            return $wordTotal <= 0.0
                ? self::letterSpread($items, $missing)
                : new LineSpread(self::share($words, $missing, $wordTotal));
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
     * The word spacing (Tw) that changes each word space by $word, unscaled by the style's
     * horizontal scale as Tw is; none for null.
     */
    private static function wordSpace(?float $word, TextStyle $style): float
    {
        return null === $word ? 0.0 : ($word * 100.0) / CharFormat::of($style)->horizontalScale;
    }

    /**
     * A word space's glyphs and width on a line whose justification changes its word spaces only:
     * as justified() gives them, without walking the glyphs. The change goes on its last glyph
     * (any other item is unchanged, and run() takes it as it is).
     *
     * @return array{0: list<Glyph>, 1: float, 2: ?float} the glyphs, the width before scaling, and
     *                                                    the last glyph's new kerning if it changed
     */
    private static function wordSpaced(Item $item, int $index, LineSpread $spread): array
    {
        $glyphs = null === $item->run ? [] : $item->run->glyphs;
        $word   = $spread->words[$index] ?? 0.0;
        $last   = count($glyphs) - 1;
        $glyph  = $glyphs[$last] ?? null;
        if (0.0 === $word || null === $glyph) {
            return [$glyphs, $item->width, null];
        }
        $format  = $item->format;
        $toEm    = 1000.0 / (($format->horizontalScale / 100.0) * $format->size);
        $kerning = $glyph->kerning + ($word * $toEm);
        // A line's word spaces are mostly one glyph stretched alike: the glyph is made once.
        $stretched = self::$stretched;
        if (null === $stretched || $glyph !== $stretched[0] || $kerning !== $stretched[1]) {
            $stretched       = [$glyph, $kerning, $glyph->withKerning($kerning)];
            self::$stretched = $stretched;
        }
        $glyphs[$last] = $stretched[2];

        return [$glyphs, $item->width + $word, $kerning];
    }

    /**
     * The glyph the style's font's word spacing moves, for a line whose justification changes its
     * word spaces only; null for any other line, or when there is none.
     */
    private static function wordSpaceGlyph(TextStyle $style, LineSpread $spread): ?int
    {
        if ([] !== $spread->letters || [] === $spread->words) {
            return null;
        }
        $font = StyleResolver::fontOf($style);

        return $font instanceof PdfFontInterface ? $font->wordSpaceGlyph() : null;
    }

    /**
     * How a justified line takes its slack when its styles let only its word spaces change, as
     * most do: each in proportion to how far it may; null when any lets more change.
     *
     * @param list<Item> $items
     */
    private static function wordSpread(array $items, float $missing): ?LineSpread
    {
        $stretching = $missing >= 0.0;
        $words      = [];
        $total      = 0.0;
        $glue       = null;
        $flex       = 0.0;
        foreach ($items as $k => $item) {
            $format = $item->format;
            if (
                $stretching
                    ? 0.0 !== $format->letterStretch
                        || 0.0 !== $format->glyphStretch
                    : 0.0 !== $format->letterShrink
                        || 0.0 !== $format->glyphShrink
            ) {
                return null;
            }
            if (ItemKind::Glue !== $item->kind) {
                continue;
            }
            // A line's word spaces are mostly one item, shared: its flexibility is worked out once.
            if ($item !== $glue) {
                $glue = $item;
                $flex = $item->spaceWidth() * ($stretching ? $format->stretch : $format->shrink);
            }
            $words[$k] = $flex;
            $total     += $flex;
        }

        return $total <= 0.0
            ? self::letterSpread($items, $missing)
            : new LineSpread(self::share($words, $missing, $total));
    }
}
