<?php

declare(strict_types=1);

namespace PhpPdf\Text\Flow;

use PhpPdf\Canvas\Canvas;
use PhpPdf\Fit\Fit;
use PhpPdf\Geometry\Box;
use PhpPdf\Geometry\Matrix;

use function max;

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

    public static function draw(Canvas $canvas, Box $box, Layout $layout, float $shift): void
    {
        if ([] === $layout->lines) {
            return;
        }
        $canvas->save();
        $topDown = $canvas->isTopDown();
        foreach ($layout->lines as [$line, $baseline]) {
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
                $style = $segment->style;
                $font  = StyleResolver::fontOf($style);
                $size  = $style->fontSize();
                $x     = $x0 + $segment->x;
                if (null !== $style->color) {
                    $canvas->setFillColor($style->color);
                }
                $matrix = new Matrix(1.0, 0.0, 0.0, $topDown ? -1.0 : 1.0, $x, $y);
                $canvas->showRun($segment->run, $font, $size, $matrix, $style->textOptions());
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
}
