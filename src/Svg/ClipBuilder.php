<?php

declare(strict_types=1);

namespace PhpPdf\Svg;

use PhpPdf\Canvas\Canvas;
use PhpPdf\Geometry\Box;
use PhpPdf\Geometry\Matrix;

use function array_key_exists;
use function ltrim;
use function trim;

/**
 * Applies <clipPath> elements: the union of their shapes, in user space or scaled to the clipped
 * element's bounding box, intersected with any clip path of their own.
 *
 * @internal
 */
final class ClipBuilder
{
    /** @var array<string, true> */
    private array $expanding = [];

    public function __construct(
        private readonly Context $context,
    ) {}

    /**
     * Intersects the clip with a <clipPath>, in user space or the clipped element's bounding box.
     *
     * @param array<string, true> $expanding ids being expanded by <use>, excluded from bounds
     */
    public function apply(Canvas $canvas, string $id, Element $clipped, Style $style, array $expanding): void
    {
        $this->expanding = $expanding;
        $clipPath        = $this->context->file->byId($id);
        if (null === $clipPath || 'clipPath' !== $clipPath->name) {
            return;
        }
        $clipStyle = $style->derive($clipPath, $this->context->sheet);
        $nested    = $clipStyle->clipPathId();
        if (null !== $nested && $nested !== $id) {
            $this->apply($canvas, $nested, $clipped, $style, $expanding);
        }
        $matrix = TransformParser::parse($clipPath->attribute('transform'));
        if ('objectBoundingBox' === trim($clipPath->attribute('clipPathUnits') ?? 'userSpaceOnUse')) {
            $bounds = $this->bounds($clipped, $style) ?? new Box(0.0, 0.0, 1.0, 1.0);
            $matrix = $matrix->then(new Matrix($bounds->width, 0.0, 0.0, $bounds->height, $bounds->x, $bounds->y));
        }
        $combined = new PathData();
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
            foreach ($path->transformed($childMatrix)->commands as $command) {
                $combined->commands[] = $command;
            }
        }
        if ($combined->isEmpty()) {
            // An empty clip path clips everything.
            $combined->moveTo(0.0, 0.0)->close();
        }
        Painter::clipTo($canvas, $combined, $rule);
    }

    /** The bounding box of an element's geometry in its parent's user space. */
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

    private function childPath(Element $child, Style $style): ?PathData
    {
        if (Shapes::isShape($child->name)) {
            return Shapes::path($child, $style, $this->context);
        }
        if ('use' === $child->name) {
            $target = $this->useTarget($child);
            if (null === $target || ! Shapes::isShape($target->name)) {
                return null;
            }
            $path = Shapes::path($target, $style->derive($target, $this->context->sheet), $this->context);
            $x    = $this->context->length($child->attribute('x'), 'x', $style) ?? 0.0;
            $y    = $this->context->length($child->attribute('y'), 'y', $style) ?? 0.0;

            return $path?->transformed(
                TransformParser::parse($target->attribute('transform'))->then(Matrix::translation($x, $y)),
            );
        }
        if ('text' === $child->name) {
            $this->context->warn('text in clip paths');
        }

        return null;
    }

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

    private function useBounds(Element $use, Style $style): ?Box
    {
        $target = $this->useTarget($use);
        $id     = ltrim(trim($use->href() ?? ''), characters: '#');
        if (null === $target || array_key_exists($id, $this->expanding)) {
            return null;
        }
        $this->expanding[$id] = true;
        $box                  = $this->bounds($target, $style);
        unset($this->expanding[$id]);
        $x = $this->context->length($use->attribute('x'), 'x', $style) ?? 0.0;
        $y = $this->context->length($use->attribute('y'), 'y', $style) ?? 0.0;

        return $box?->translate($x, $y);
    }

    private function useTarget(Element $use): ?Element
    {
        $id = ltrim(trim($use->href() ?? ''), characters: '#');

        return '' === $id ? null : $this->context->file->byId($id);
    }
}
