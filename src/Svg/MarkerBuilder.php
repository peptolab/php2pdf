<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Canvas\FillRule;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Geometry\Matrix;
use Php2Pdf\Geometry\Origin;
use Php2Pdf\Geometry\Path;
use Php2Pdf\Template\Template;
use WeakMap;

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
 * clipped unless overflow is visible. Each marker is drawn once, as a form placed at every vertex
 * it marks, and is expanded through the reference guard.
 *
 * @internal
 */
final readonly class MarkerBuilder
{
    /** Elements that take markers. */
    public const array MARKABLE = ['path' => true, 'line' => true, 'polyline' => true, 'polygon' => true];

    /** Half the side of the box a marker whose overflow is visible may draw in. */
    private const float HUGE = 100_000.0;

    /** @var WeakMap<Element, Template> each marker's form */
    private WeakMap $forms;

    public function __construct(
        private Context $context,
        private ChildrenDrawerInterface $children,
    ) {
        $this->forms = new WeakMap();
    }

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

    /**
     * @throws IoException
     * @throws ParseException for a marker drawn within itself, directly or through other references
     */
    public function draw(Canvas $canvas, Path $path, Style $style): void
    {
        $ids = [$style->markerId('start'), $style->markerId('mid'), $style->markerId('end')];
        if ([null, null, null] === $ids) {
            return;
        }
        $vertices    = MarkerVertices::of($path);
        $last        = count($vertices) - 1;
        $strokeWidth = $style->strokeWidth($this->context->viewportDiagonal());
        foreach ($vertices as $i => [$x, $y, $angle]) {
            if (0 === $i && null !== $ids[0]) {
                $this->marker($canvas, $ids[0], [$x, $y, $angle], $strokeWidth, 'start');
            }
            if (0 !== $i && $last !== $i && null !== $ids[1]) {
                $this->marker($canvas, $ids[1], [$x, $y, $angle], $strokeWidth, 'mid');
            }
            if ($last === $i && null !== $ids[2]) {
                $this->marker($canvas, $ids[2], [$x, $y, $angle], $strokeWidth, 'end');
            }
        }
    }

    /**
     * A marker's form, drawn the first time it is placed: its viewport, clipped unless its
     * overflow is visible, with its content mapped through the viewBox.
     *
     * @throws IoException
     * @throws ParseException
     */
    private function form(string $id, Element $marker, float $width, float $height, Matrix $mapping): Template
    {
        $form = $this->forms[$marker] ?? null;
        if (null !== $form) {
            return $form;
        }
        $style   = Style::root()->derive($marker, $this->context->sheet);
        $visible = $style->overflowVisible();
        $form    = $this->context->document->template(
            new Dimensions($width, $height),
            Origin::BottomLeft,
            $visible ? new Box(-self::HUGE, -self::HUGE, 2.0 * self::HUGE, 2.0 * self::HUGE) : null,
        );
        $canvas = $form->canvas();
        if (! $visible) {
            Painter::clipTo($canvas, Path::rect(0.0, 0.0, $width, $height), FillRule::NonZero);
        }
        $canvas->concat($mapping);
        $this->context->guard->within(
            'marker',
            $id,
            /**
             * @throws IoException
             * @throws ParseException
             */
            function () use ($canvas, $marker, $style): void {
                $this->children->drawChildren($canvas, $marker, $style);
            },
        );
        $this->forms[$marker] = $form;

        return $form;
    }

    /**
     * @param array{0: float, 1: float, 2: float} $vertex   x, y and the path's angle there
     * @param 'start'|'mid'|'end'                 $position
     *
     * @throws IoException
     * @throws ParseException
     */
    private function marker(Canvas $canvas, string $id, array $vertex, float $strokeWidth, string $position): void
    {
        $marker = $this->context->file->byId($id);
        if (null === $marker || 'marker' !== $marker->name) {
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
        $placement = Matrix::translation(-$refX, -$refY)
            ->then(Matrix::scaling($scale))
            ->then(Matrix::rotation($angle))
            ->then(Matrix::translation($vertex[0], $vertex[1]));
        $canvas->drawXObject($this->form($id, $marker, $width, $height, $mapping)->reference(), $placement);
    }
}
