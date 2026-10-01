<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Closure;
use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Canvas\FillRule;
use Php2Pdf\Geometry\Matrix;

use function array_key_exists;
use function count;
use function preg_match;
use function strtolower;
use function trim;

use const M_PI;

/**
 * Draws <marker> elements at the vertices of paths, lines, polylines and polygons: marker-start
 * at the first vertex, marker-mid at the others, marker-end at the last. Each marker's viewport
 * (markerWidth by markerHeight, scaled by the stroke width with the default
 * markerUnits="strokeWidth") is placed with its refX/refY point on the vertex, rotated to the
 * path's direction there with orient="auto" or "auto-start-reverse", or by a fixed angle, and
 * clipped unless overflow is visible.
 *
 * @internal
 */
final class MarkerBuilder
{
    /** Elements that take markers. */
    public const array MARKABLE = ['path' => true, 'line' => true, 'polyline' => true, 'polygon' => true];

    /** @var array<string, true> markers being drawn, to break cycles */
    private array $drawing = [];

    /** @param Closure(Canvas, Element, Style): void $children draws an element's children */
    public function __construct(
        private readonly Context $context,
        private readonly Closure $children,
    ) {}

    /** An orient angle in degrees: a number with an optional deg, rad, grad or turn unit. */
    private static function fixedAngle(string $orient): float
    {
        $m = [];
        if (1 !== preg_match('/^([+-]?(?:\d+\.?\d*|\.\d+)(?:e[+-]?\d+)?)(deg|rad|grad|turn)?$/', $orient, $m)) {
            return 0.0;
        }
        $value = Length::toFloat($m[1] ?? '0');

        return match ($m[2] ?? '') {
            'rad'   => ($value * 180.0) / M_PI,
            'grad'  => $value * 0.9,
            'turn'  => $value * 360.0,
            default => $value,
        };
    }

    public function draw(Canvas $canvas, PathData $path, Style $style): void
    {
        $ids = [$style->markerId('start'), $style->markerId('mid'), $style->markerId('end')];
        if ([null, null, null] === $ids) {
            return;
        }
        $vertices = MarkerVertices::of($path);
        $last     = count($vertices) - 1;
        foreach ($vertices as $i => [$x, $y, $angle]) {
            if (0 === $i && null !== $ids[0]) {
                $this->marker($canvas, $ids[0], [$x, $y, $angle], $style->strokeWidth(), 'start');
            }
            if (0 !== $i && $last !== $i && null !== $ids[1]) {
                $this->marker($canvas, $ids[1], [$x, $y, $angle], $style->strokeWidth(), 'mid');
            }
            if ($last === $i && null !== $ids[2]) {
                $this->marker($canvas, $ids[2], [$x, $y, $angle], $style->strokeWidth(), 'end');
            }
        }
    }

    /**
     * @param array{0: float, 1: float, 2: float} $vertex   x, y and the path's angle there
     * @param 'start'|'mid'|'end'                 $position
     */
    private function marker(Canvas $canvas, string $id, array $vertex, float $strokeWidth, string $position): void
    {
        $marker = $this->context->file->byId($id);
        if (null === $marker || 'marker' !== $marker->name || array_key_exists($id, $this->drawing)) {
            return;
        }
        $width  = Length::parse($marker->attribute('markerWidth') ?? '3', 0.0) ?? 3.0;
        $height = Length::parse($marker->attribute('markerHeight') ?? '3', 0.0) ?? 3.0;
        if ($width <= 0.0 || $height <= 0.0) {
            return;
        }
        $orient = strtolower(trim($marker->attribute('orient') ?? '0'));
        $angle  = match ($orient) {
            'auto'               => $vertex[2],
            'auto-start-reverse' => 'start' === $position ? $vertex[2] + 180.0 : $vertex[2],
            default              => self::fixedAngle($orient),
        };
        $scale   = 'userSpaceOnUse' === trim($marker->attribute('markerUnits') ?? '') ? 1.0 : $strokeWidth;
        $mapping = Viewport::mapping(
            Viewport::viewBox($marker),
            $width,
            $height,
            $marker->attribute('preserveAspectRatio'),
        );
        [$refX, $refY] = $mapping->apply(
            Length::parse($marker->attribute('refX'), 0.0) ?? 0.0,
            Length::parse($marker->attribute('refY'), 0.0) ?? 0.0,
        );
        $markerStyle = Style::root()->derive($marker, $this->context->sheet);
        $canvas->save();
        $canvas->concat(
            Matrix::translation(-$refX, -$refY)
                ->then(Matrix::scaling($scale))
                ->then(Matrix::rotation($angle))
                ->then(Matrix::translation($vertex[0], $vertex[1])),
        );
        if (! $markerStyle->overflowVisible()) {
            Painter::clipTo($canvas, PathData::rect(0.0, 0.0, $width, $height), FillRule::NonZero);
        }
        $canvas->concat($mapping);
        $this->drawing[$id] = true;
        ($this->children)($canvas, $marker, $markerStyle);
        unset($this->drawing[$id]);
        $canvas->restore();
    }
}
