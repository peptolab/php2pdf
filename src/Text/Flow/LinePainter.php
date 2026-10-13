<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Closure;
use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Canvas\Tagger;
use Php2Pdf\Color\ColorInterface;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Font\Bidi\BidiRuns;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Matrix;
use Php2Pdf\Navigation\Destination;
use Php2Pdf\Structure\StructureElement;
use Php2Pdf\Text\Style\CharFormat;
use Php2Pdf\Text\Style\ParagraphFormat;
use Php2Pdf\Text\Style\TextStyle;

use function abs;
use function max;
use function serialize;

/**
 * Draws laid-out lines on a canvas: each run of text with its colour, underline and strikeout,
 * and the artwork set in the line.
 *
 * @internal
 */
final class LinePainter
{
    /** @return array{0: float, 1: float} */
    public static function baselinePoint(Canvas $canvas, Box $box, Line $line, float $offset): array
    {
        $x = $box->x + $line->x;
        $y = $canvas->isTopDown() ? $box->y + $offset : $box->far() - $offset;

        return [$x, $y];
    }

    /**
     * Draws the lines, and on a tagged page tags their text as their paragraphs' and links'
     * elements, and everything else as artifacts.
     *
     * @return list<StructureElement> on a tagged page the elements of the link areas
     *                                markedAreas() finds, in order
     */
    public static function draw(
        Canvas $canvas,
        Box $box,
        LayoutResult $layout,
        float $shift,
        ?FlowTags $tags = null,
    ): array {
        if ([] === $layout->lines) {
            return [];
        }
        $tagger = $canvas->tagger();
        if (null !== $tagger) {
            $tags?->enter($tagger);
        }
        $canvas->within(null, static function (Canvas $canvas) use ($box, $layout, $shift, $tags, $tagger): void {
            self::paint($canvas, $box, $layout, $shift, null === $tagger || null === $tags ? null : [$tags, $tagger]);
        });

        return $tags?->drawnLinks() ?? [];
    }

    /** What a link run shares: its target and tooltip. */
    public static function linkKey(TextStyle $style): ?string
    {
        return null === $style->link ? null : serialize([$style->link, $style->linkTooltip]);
    }

    /**
     * The areas of linked and matched text, in canvas coordinates: per line, one area for each
     * run of touching segments with the same link (and tooltip) or match name, from the fonts'
     * descenders to their ascenders.
     *
     * @return array{0: list<array{0: Box, 1: Destination|string, 2: ?string}>, 1: array<string, list<Box>>}
     *         links with their targets and tooltips, and matched areas by name
     */
    public static function markedAreas(Canvas $canvas, Box $box, LayoutResult $layout, float $shift): array
    {
        $links   = [];
        $matches = [];
        foreach ($layout->lines as $placed) {
            [$line, $baseline] = [$placed->line, $placed->baseline];
            if (! self::isMarked($line)) {
                continue;
            }
            [$x0, $y] = self::baselinePoint($canvas, $box, $line, $baseline + $shift);
            // A run opens only on a segment whose key is set, and keeps that segment's style.
            foreach (self::runs($line, self::linkKey(...)) as [$left, $right, $ascent, $descent, $style]) {
                /** @var Destination|string $link */
                $link    = $style->link;
                $area    = self::area($canvas, $x0 + $left, $x0 + $right, $y, $ascent, $descent);
                $links[] = [$area, $link, $style->linkTooltip];
            }
            foreach (self::runs($line, static fn(TextStyle $s): ?string => $s->match) as [
                $left,
                $right,
                $ascent,
                $descent,
                $style,
            ]) {
                /** @var string $match */
                $match             = $style->match;
                $matches[$match][] = self::area($canvas, $x0 + $left, $x0 + $right, $y, $ascent, $descent);
            }
        }

        return [$links, $matches];
    }

    /** How far a line's text reaches from its start, as drawn (justified lines included). */
    public static function textWidth(Line $line): float
    {
        $width = 0.0;
        foreach ($line->segments as $segment) {
            $width = max($width, $segment->x + $segment->width);
        }
        foreach ($line->inlines as [$x, $inline]) {
            $width = max($width, $x + $inline->width);
        }

        return $width;
    }

    private static function area(
        Canvas $canvas,
        float $left,
        float $right,
        float $baseline,
        float $ascent,
        float $descent,
    ): Box {
        return new Box(
            $left,
            $canvas->isTopDown() ? $baseline - $ascent : $baseline - $descent,
            $right - $left,
            $ascent + $descent,
        );
    }

    /**
     * Underlines and strikeouts, drawn after the text object they may not be drawn in, each in the
     * colour its segment's text was drawn in.
     *
     * @param list<array{0: ?ColorInterface, 1: float, 2: float, 3: float, 4: float}> $bars
     */
    private static function bars(Canvas $canvas, array $bars): void
    {
        foreach ($bars as [$color, $x, $y, $width, $height]) {
            if (null !== $color) {
                $canvas->setFillColor($color);
            }
            $canvas->fillRect($x, $y, $width, $height);
        }
    }

    /**
     * The underline and strikeout of a segment, if its style has them.
     *
     * @return list<array{0: ?ColorInterface, 1: float, 2: float, 3: float, 4: float}>
     */
    private static function decorations(Segment $segment, float $x, float $y, float $sign): array
    {
        $style = $segment->style;
        $font  = StyleResolver::fontOf($style);
        $size  = CharFormat::of($style)->size;
        $bars  = [];
        if (true === $style->underline) {
            $t      = $font->underlineThickness($size);
            $bars[] = [
                $style->color,
                $x,
                $y - ($sign * ($font->underlinePosition($size) - ($t / 2.0)) * -1.0),
                $segment->width,
                $sign * $t,
            ];
        }
        if (true === $style->strikeout) {
            $t      = $font->strikeoutSize($size);
            $bars[] = [
                $style->color,
                $x,
                $y - ($sign * ($font->strikeoutPosition($size) - ($t / 2.0)) * -1.0),
                $segment->width,
                $sign * $t,
            ];
        }

        return $bars;
    }

    /** Whether any of a line's text is linked or matched: most lines have neither. */
    private static function isMarked(Line $line): bool
    {
        foreach ($line->segments as $segment) {
            if (null !== $segment->style->link || null !== $segment->style->match) {
                return true;
            }
        }

        return false;
    }

    /**
     * Draws a list item's marker on the line that starts it, in the item's style: ending half an
     * em before the item's text, in the space its indent leaves (after it, for a right-to-left
     * item). Returns the fill colour left set.
     *
     * @param array{0: FlowTags, 1: Tagger}|null $tags
     */
    private static function marker(
        Canvas $canvas,
        Box $box,
        PlacedLine $placed,
        float $y,
        ?array $tags,
        ?ColorInterface $lastColor,
    ): ?ColorInterface {
        $item  = $placed->starts;
        $list  = $item?->list;
        $label = $list?->label;
        if (null === $item || null === $list || null === $label) {
            return $lastColor;
        }
        $element   = null === $tags ? null : $tags[0]->label($tags[1], $list);
        $style     = $item->style;
        $font      = StyleResolver::fontOf($style);
        $format    = CharFormat::of($style);
        $paragraph = ParagraphFormat::of($style);
        $run       = BidiRuns::shape($font, $label, $format->textOptions);
        $gap       = 0.5 * $format->size;
        $x         = 1 === $item->level
            ? $box->right() - $paragraph->rightIndent + $gap
            : $box->x
                + $paragraph->leftIndent
                + $paragraph->firstLineIndent
                - $gap
            - $font->width($run, $format->size, $format->textOptions);
        $canvas->mark($element);
        if (null !== $style->color && $style->color !== $lastColor) {
            $canvas->setFillColor($style->color);
            $lastColor = $style->color;
        }
        $canvas->showRun(
            $run,
            $font,
            $format->size,
            new Matrix(1.0, 0.0, 0.0, $canvas->isTopDown() ? -1.0 : 1.0, $x, $y),
            $format->textOptions,
        );

        return $lastColor;
    }

    /**
     * Draws the lines, and the markers of the list items they start, tagging their text when
     * given the flow's elements.
     *
     * @param array{0: FlowTags, 1: Tagger}|null $tags the flow's elements, and the page's tagger
     */
    private static function paint(Canvas $canvas, Box $box, LayoutResult $layout, float $shift, ?array $tags): void
    {
        $canvas->save();
        $canvas->beginText();
        $topDown = $canvas->isTopDown();
        // Segments mostly share a colour: it is set again only when it changes, or when artwork
        // placed since may have changed it.
        $lastColor = null;
        $bars      = [];
        foreach ($layout->lines as $placed) {
            [$line, $baseline] = [$placed->line, $placed->baseline];
            [$x0, $y] = self::baselinePoint($canvas, $box, $line, $baseline + $shift);
            $elements = null === $tags
                ? []
                : $tags[0]->line($tags[1], $placed, self::isMarked($line) ? self::runs($line, self::linkKey(...)) : []);
            $lastColor = self::marker($canvas, $box, $placed, $y, $tags, $lastColor);
            if ([] !== $line->inlines) {
                $canvas->endText();
                $canvas->mark(null);
                foreach ($line->inlines as [$ix, $inline]) {
                    $edge = $topDown ? $y - $inline->raise - $inline->height : $y + $inline->raise;
                    $canvas->place(
                        $inline->object,
                        new Box($x0 + $ix, $edge, $inline->width, $inline->height),
                        Fit::entire(),
                    );
                }
                $canvas->beginText();
                $lastColor = null;
            }
            foreach ($line->segments as $index => $segment) {
                $canvas->mark($elements[$index] ?? null);
                $style  = $segment->style;
                $font   = StyleResolver::fontOf($style);
                $format = CharFormat::of($style);
                $size   = $format->size;
                $x      = $x0 + $segment->x;
                if (null !== $style->color && $style->color !== $lastColor) {
                    $canvas->setFillColor($style->color);
                    $lastColor = $style->color;
                }
                $matrix  = new Matrix(1.0, 0.0, 0.0, $topDown ? -1.0 : 1.0, $x, $y);
                $options = 1.0 === $segment->scale
                    ? $format->textOptions
                    : $format->textOptions->withHorizontalScale($format->horizontalScale * $segment->scale);
                $canvas->showRunPieces($segment->run, $segment->pieces, $font, $size, $matrix, $options);
                foreach (self::decorations($segment, $x, $y, $topDown ? -1.0 : 1.0) as $bar) {
                    $bars[] = $bar;
                }
            }
        }
        $canvas->endText();
        $canvas->mark(null);
        self::bars($canvas, $bars);
        $canvas->restore();
    }

    /**
     * Runs of touching segments that share a key, with their extent along the line and the
     * largest ascent and descent of their fonts. A segment without a key ends a run.
     *
     * @param Closure(TextStyle): ?string $key
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float, 4: TextStyle, 5: list<int>}> each
     *         with the indices of its segments last
     */
    private static function runs(Line $line, Closure $key): array
    {
        $runs    = [];
        $open    = [0.0, 0.0, 0.0, 0.0, $line->style, []];
        $isOpen  = false;
        $current = null;
        foreach ($line->segments as $index => $segment) {
            $style = $segment->style;
            $k     = $key($style);
            if ($isOpen && ($k !== $current || abs($open[1] - $segment->x) >= 0.01)) {
                $runs[] = $open;
                $isOpen = false;
            }
            $current = $k;
            if (null === $k) {
                continue;
            }
            $font    = StyleResolver::fontOf($style);
            $format  = CharFormat::of($style);
            $size    = $format->size;
            $rise    = $format->rise;
            $ascent  = $font->ascender($size) + $rise;
            $descent = -$font->descender($size) - $rise;
            $right   = $segment->x + $segment->width;
            $open    = $isOpen
                ? [
                    $open[0],
                    max($open[1], $right),
                    max($open[2], $ascent),
                    max($open[3], $descent),
                    $open[4],
                    [...$open[5], $index],
                ]
                : [$segment->x, $right, $ascent, $descent, $style, [$index]];
            $isOpen = true;
        }
        if ($isOpen) {
            $runs[] = $open;
        }

        return $runs;
    }
}
