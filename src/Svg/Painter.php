<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Canvas\FillRule;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Path;

/**
 * Fills and strokes path data with a computed style, resolving gradients and patterns against
 * the path's bounds.
 *
 * @internal
 */
final class Painter
{
    public function __construct(
        private readonly Context $context,
        private readonly PaintResolver $paints,
    ) {}

    /** Clips to a path with the given rule. */
    public static function clipTo(Canvas $canvas, Path $path, FillRule $rule): void
    {
        $canvas->path($path);
        $canvas->clip($rule);
    }

    /**
     * Sets the fill colour, gradient or pattern; returns whether there is anything to fill.
     *
     * @throws IoException
     * @throws ParseException
     */
    public function fill(Canvas $canvas, Style $style, Box $bounds): bool
    {
        return $this->paints->resolve($style->fill(), $bounds, $style)->fill($canvas);
    }

    /**
     * @throws IoException
     * @throws ParseException
     */
    public function paint(Canvas $canvas, Path $path, Style $style, ?Box $bounds = null): void
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
        $canvas->path($path);
        $rule = $style->fillRule();
        match (true) {
            $fill && $stroke => $canvas->fillAndStroke($rule),
            $fill   => $canvas->fill($rule),
            default => $canvas->stroke(),
        };
    }

    /**
     * Sets the stroke colour, gradient or pattern and line style; returns whether there is anything to stroke.
     *
     * @throws ParseException
     */
    public function stroke(Canvas $canvas, Style $style, Box $bounds): bool
    {
        $width = $style->strokeWidth($this->context->viewportDiagonal());
        if ($width <= 0.0 || ! $this->paints->resolve($style->stroke(), $bounds, $style)->stroke($canvas)) {
            return false;
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
