<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Closure;
use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Matrix;
use Php2Pdf\Navigation\Destination;
use Php2Pdf\Text\Style\CharFormat;
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

    public static function draw(Canvas $canvas, Box $box, LayoutResult $layout, float $shift): void
    {
        if ([] === $layout->lines) {
            return;
        }
        $canvas->save();
        $topDown = $canvas->isTopDown();
        foreach ($layout->lines as $placed) {
            [$line, $baseline] = [$placed->line, $placed->baseline];
            [$x0, $y] = self::baselinePoint($canvas, $box, $line, $baseline + $shift);
            foreach ($line->inlines as [$ix, $inline]) {
                $edge = $topDown ? $y - $inline->raise - $inline->height : $y + $inline->raise;
                $canvas->place(
                    $inline->object,
                    new Box($x0 + $ix, $edge, $inline->width, $inline->height),
                    Fit::entire(),
                );
            }
            foreach ($line->segments as $segment) {
                $style  = $segment->style;
                $font   = StyleResolver::fontOf($style);
                $format = CharFormat::of($style);
                $size   = $format->size;
                $x      = $x0 + $segment->x;
                if (null !== $style->color) {
                    $canvas->setFillColor($style->color);
                }
                $matrix  = new Matrix(1.0, 0.0, 0.0, $topDown ? -1.0 : 1.0, $x, $y);
                $options = 1.0 === $segment->scale
                    ? $format->textOptions
                    : $format->textOptions->withHorizontalScale($format->horizontalScale * $segment->scale);
                $canvas->showRun($segment->run, $font, $size, $matrix, $options);
                $sign = $topDown ? -1.0 : 1.0;
                if (true === $style->underline) {
                    $t = $font->underlineThickness($size);
                    $canvas->fillRect(
                        $x,
                        $y - ($sign * ($font->underlinePosition($size) - ($t / 2.0)) * -1.0),
                        $segment->width,
                        $sign * $t,
                    );
                }
                if (true === $style->strikeout) {
                    $t = $font->strikeoutSize($size);
                    $canvas->fillRect(
                        $x,
                        $y - ($sign * ($font->strikeoutPosition($size) - ($t / 2.0)) * -1.0),
                        $segment->width,
                        $sign * $t,
                    );
                }
            }
        }
        $canvas->restore();
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

    private static function linkKey(TextStyle $style): ?string
    {
        return null === $style->link ? null : serialize([$style->link, $style->linkTooltip]);
    }

    /**
     * Runs of touching segments that share a key, with their extent along the line and the
     * largest ascent and descent of their fonts. A segment without a key ends a run.
     *
     * @param Closure(TextStyle): ?string $key
     *
     * @return list<array{0: float, 1: float, 2: float, 3: float, 4: TextStyle}>
     */
    private static function runs(Line $line, Closure $key): array
    {
        $runs    = [];
        $open    = [0.0, 0.0, 0.0, 0.0, $line->style];
        $isOpen  = false;
        $current = null;
        foreach ($line->segments as $segment) {
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
                ? [$open[0], max($open[1], $right), max($open[2], $ascent), max($open[3], $descent), $open[4]]
                : [$segment->x, $right, $ascent, $descent, $style];
            $isOpen = true;
        }
        if ($isOpen) {
            $runs[] = $open;
        }

        return $runs;
    }
}
