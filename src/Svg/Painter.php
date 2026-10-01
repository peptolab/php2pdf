<?php

declare(strict_types=1);

namespace PhpPdf\Svg;

use PhpPdf\Canvas\Canvas;
use PhpPdf\Canvas\FillRule;
use PhpPdf\Geometry\Box;

/**
 * Fills and strokes path data with a computed style, resolving gradients against the path's
 * bounds.
 *
 * @internal
 */
final class Painter
{
    public function __construct(
        private readonly Context $context,
    ) {}

    /** Clips to a path with the given rule. */
    public static function clipTo(Canvas $canvas, PathData $path, FillRule $rule): void
    {
        $path->emit($canvas);
        $canvas->clip($rule);
    }

    /** Sets the fill colour or gradient; returns whether there is anything to fill. */
    public function fill(Canvas $canvas, Style $style, Box $bounds): bool
    {
        $paint = $style->fill();
        if ($paint->isNone()) {
            return false;
        }
        if (null !== $paint->serverId) {
            $gradient = $this->context->servers->gradient($paint->serverId, $bounds, $style, $this->context->sheet);
            if (null !== $gradient) {
                $canvas->setFillGradient($gradient[0], $gradient[1]);

                return true;
            }
        }
        if (null === $paint->color) {
            return false;
        }
        $canvas->setFillColor($paint->color);

        return true;
    }

    public function paint(Canvas $canvas, PathData $path, Style $style, ?Box $bounds = null): void
    {
        if (! $style->isVisible()) {
            return;
        }
        $bounds ??= $path->bounds() ?? new Box(0.0, 0.0, 1.0, 1.0);
        $fill   = $this->fill($canvas, $style, $bounds);
        $stroke = $this->stroke($canvas, $style, $bounds);
        if (! $fill && ! $stroke) {
            return;
        }
        $fillOpacity   = $style->fillOpacity();
        $strokeOpacity = $style->strokeOpacity();
        if ($fillOpacity < 1.0 || $strokeOpacity < 1.0) {
            $canvas->setOpacity($fillOpacity, $strokeOpacity);
        }
        $path->emit($canvas);
        $rule = $style->fillRule();
        match (true) {
            $fill && $stroke => $canvas->fillAndStroke($rule),
            $fill   => $canvas->fill($rule),
            default => $canvas->stroke(),
        };
    }

    /** Sets the stroke colour, gradient and line style; returns whether there is anything to stroke. */
    public function stroke(Canvas $canvas, Style $style, Box $bounds): bool
    {
        $paint = $style->stroke();
        $width = $style->strokeWidth();
        if ($paint->isNone() || $width <= 0.0) {
            return false;
        }
        $gradient = null === $paint->serverId
            ? null
            : $this->context->servers->gradient($paint->serverId, $bounds, $style, $this->context->sheet);
        if (null !== $gradient) {
            $canvas->setStrokeGradient($gradient[0], $gradient[1]);
        }
        if (null === $gradient) {
            if (null === $paint->color) {
                return false;
            }
            $canvas->setStrokeColor($paint->color);
        }
        $canvas->setLineWidth($width)
            ->setLineCap($style->lineCap())
            ->setLineJoin($style->lineJoin())
            ->setMiterLimit($style->miterLimit());
        $dashes = $style->dashArray();
        if ([] !== $dashes) {
            $canvas->setDash($dashes, $style->dashOffset());
        }

        return true;
    }
}
