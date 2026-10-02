<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Matrix;
use Php2Pdf\Geometry\Path;

/**
 * Applies <clipPath> elements: the union of their shapes, in user space or scaled to the clipped
 * element's bounding box, intersected with any clip path of their own. Clip paths, and the
 * <use> elements followed for bounding boxes, are expanded through the reference guard.
 *
 * @internal
 */
final readonly class ClipBuilder
{
    public function __construct(
        private Context $context,
    ) {}

    /**
     * Intersects the clip with a <clipPath>, in user space or the clipped element's bounding box.
     *
     * @throws ParseException for a clip path that clips itself, directly or through other references
     */
    public function apply(Canvas $canvas, string $id, Element $clipped, Style $style): void
    {
        $clipPath = $this->context->file->byId($id);
        if (null === $clipPath || 'clipPath' !== $clipPath->name) {
            return;
        }
        $this->context->guard->within(
            'clip-path',
            $id,
            /** @throws ParseException */ function () use ($canvas, $clipPath, $clipped, $style): void {
                $this->clip($canvas, $clipPath, $clipped, $style);
            },
        );
    }

    /**
     * The bounding box of an element's geometry in its parent's user space.
     *
     * @throws ParseException
     */
    public function bounds(Element $element, Style $style): ?Box
    {
        $transform = TransformParser::parse($element->attribute('transform'));
        $box       = match (true) {
            Shapes::isShape($element->name) => Shapes::path($element, $style, $this->context)?->bounds(),
            'use' === $element->name => $this->useBounds($element, $style),
            'image' === $element->name => $this->box($element, $style),
            'g' === $element->name, 'a' === $element->name, 'svg' === $element->name => $this->childrenBounds(
                $element,
                $style,
            ),
            default => null,
        };

        return null === $box ? null : $transform->applyToBox($box);
    }

    /** The x, y, width, height attributes as a box, or null without a positive size. */
    private function box(Element $element, Style $style): ?Box
    {
        $width  = $this->context->length($element->attribute('width'), 'x', $style) ?? 0.0;
        $height = $this->context->length($element->attribute('height'), 'y', $style) ?? 0.0;
        if ($width <= 0.0 || $height <= 0.0) {
            return null;
        }

        return new Box(
            $this->context->length($element->attribute('x'), 'x', $style) ?? 0.0,
            $this->context->length($element->attribute('y'), 'y', $style) ?? 0.0,
            $width,
            $height,
        );
    }

    /** @throws ParseException */
    private function childPath(Element $child, Style $style): ?Path
    {
        if (Shapes::isShape($child->name)) {
            return Shapes::path($child, $style, $this->context);
        }
        if ('use' === $child->name) {
            $target = $this->useTarget($child);
            if (null === $target || ! Shapes::isShape($target->name)) {
                return null;
            }
            $path = $this->context->guard->within(
                'use',
                $target->id() ?? '',
                fn(): ?Path => Shapes::path(
                    $target,
                    $style->derive($target, $this->context->sheet),
                    $this->context,
                ),
            );
            $x = $this->context->length($child->attribute('x'), 'x', $style) ?? 0.0;
            $y = $this->context->length($child->attribute('y'), 'y', $style) ?? 0.0;

            return $path?->transformed(
                TransformParser::parse($target->attribute('transform'))->then(Matrix::translation($x, $y)),
            );
        }
        if ('text' === $child->name) {
            $this->context->warn('text in clip paths');
        }

        return null;
    }

    /** @throws ParseException */
    private function childrenBounds(Element $element, Style $style): ?Box
    {
        $box = null;
        foreach ($element->elements() as $child) {
            $childBox = $this->bounds($child, $style->derive($child, $this->context->sheet));
            if (null === $childBox) {
                continue;
            }
            $box = null === $box ? $childBox : $box->union($childBox);
        }

        return $box;
    }

    /**
     * Clips to a <clipPath> already entered in the guard, after any clip path of its own. A clip
     * path with nothing in it clips everything away.
     *
     * @throws ParseException
     */
    private function clip(Canvas $canvas, Element $clipPath, Element $clipped, Style $style): void
    {
        $clipStyle = $style->derive($clipPath, $this->context->sheet);
        $nested    = $clipStyle->clipPathId();
        if (null !== $nested) {
            $this->apply($canvas, $nested, $clipped, $style);
        }
        $matrix = TransformParser::parse($clipPath->attribute('transform'));
        $units  = Units::of($clipPath->attribute('clipPathUnits'), Units::UserSpaceOnUse);
        if (Units::ObjectBoundingBox === $units) {
            $matrix = $matrix->then($units->boxMatrix(
                $this->bounds($clipped, $style) ?? new Box(0.0, 0.0, 1.0, 1.0),
            ));
        }
        $combined = new Path();
        $rule     = $clipStyle->clipRule();
        foreach ($clipPath->elements() as $child) {
            $childStyle = $clipStyle->derive($child, $this->context->sheet);
            if (! $childStyle->isDisplayed() || ! $childStyle->isVisible()) {
                continue;
            }
            $rule = $childStyle->clipRule();
            $path = $this->childPath($child, $childStyle);
            if (null === $path) {
                continue;
            }
            $childMatrix = TransformParser::parse($child->attribute('transform'))->then($matrix);
            $combined    = $combined->append($path->transformed($childMatrix));
        }
        if ($combined->isEmpty()) {
            $combined = new Path([['M', [0.0, 0.0]], ['Z', []]]);
        }
        Painter::clipTo($canvas, $combined, $rule);
    }

    /** @throws ParseException */
    private function useBounds(Element $use, Style $style): ?Box
    {
        $target = $this->useTarget($use);
        if (null === $target) {
            return null;
        }
        $box = $this->context->guard->within(
            'use',
            $target->id() ?? '',
            /** @throws ParseException */
            fn(): ?Box => $this->bounds($target, $style),
        );
        $x = $this->context->length($use->attribute('x'), 'x', $style) ?? 0.0;
        $y = $this->context->length($use->attribute('y'), 'y', $style) ?? 0.0;

        return $box?->translate($x, $y);
    }

    /** @throws ParseException */
    private function useTarget(Element $use): ?Element
    {
        $id = ElementRef::fromHref($use->href());

        return null === $id ? null : $this->context->file->byId($id);
    }
}
