<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Text\Style\ParagraphFormat;
use Php2Pdf\Text\Style\TabAlignment;
use Php2Pdf\Text\Style\TabStop;
use Php2Pdf\Text\Style\TextStyle;
use WeakMap;

use function ceil;
use function floor;
use function max;
use function strpos;
use function substr;
use function usort;

/**
 * A paragraph style's tabs: its stops in order, and the default stops after the last. Where the
 * text after a tab goes (left, right, centre or decimal aligned at the stop), and the leader that
 * fills the gap. Made once per style.
 *
 * @internal
 */
final class TabRuler
{
    /** @var WeakMap<TextStyle, self>|null */
    private static ?WeakMap $rulers = null;

    /** @var list<TabStop> */
    private readonly array $stops;

    private function __construct(
        private readonly TextStyle $style,
    ) {
        $stops = ParagraphFormat::of($style)->tabs;
        usort($stops, static fn(TabStop $a, TabStop $b): int => $a->position <=> $b->position);
        $this->stops = $stops;
    }

    /** The ruler of a paragraph style. */
    public static function of(TextStyle $style): self
    {
        self::$rulers ??= new WeakMap();

        return self::$rulers[$style] ??= new self($style);
    }

    /**
     * The leader filling the gap of the tab at $from up to $to, in the style of the tab: whole
     * repeats of the stop's leader text on a grid of its width from the line's start, or null
     * when the stop has none or none fits.
     */
    public function leader(float $from, float $to, TextStyle $style): ?Segment
    {
        $text = $this->stopAfter($from)?->leader;
        if (null === $text) {
            return null;
        }
        $unit = ItemFactory::box($text, $style);
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
     * The x position after a tab, accounting for right, centre and decimal alignment of the text
     * that follows it up to the next tab.
     *
     * @param list<Item> $items
     */
    public function target(float $x, array $items, int $from): float
    {
        $stop = $this->stopAfter($x);
        if (null === $stop) {
            return $this->nextTab($x);
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
            $prefix       = ItemFactory::box(substr($item->text, offset: 0, length: $pos), $item->style);
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

    /** The next default tab position after $x. */
    private function nextTab(float $x): float
    {
        foreach ($this->stops as $tab) {
            if ($tab->position > ($x + 1.0e-6)) {
                return $tab->position;
            }
        }
        $step = max(1.0, ParagraphFormat::of($this->style)->defaultTabWidth);

        return (floor(($x / $step) + 1.0e-6) + 1.0) * $step;
    }

    /**
     * The first tab stop after $x, or null past the last one (default tabs follow).
     */
    private function stopAfter(float $x): ?TabStop
    {
        foreach ($this->stops as $tab) {
            if ($tab->position > ($x + 1.0e-6)) {
                return $tab;
            }
        }

        return null;
    }
}
