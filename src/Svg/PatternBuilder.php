<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Closure;
use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Geometry\Matrix;
use Php2Pdf\Geometry\Origin;

use function array_key_exists;
use function implode;

/**
 * Resolves <pattern> paint servers into tiling patterns: the pattern's children, drawn into a
 * tile of its width and height (in user space, or as fractions of the painted element's bounding
 * box with the default patternUnits="objectBoundingBox"), repeat from its x and y through
 * patternTransform. The children are drawn in the tile's user space, through the viewBox when
 * there is one, or the bounding box with patternContentUnits="objectBoundingBox"; attributes and
 * children are inherited through href.
 *
 * @internal
 */
final class PatternBuilder
{
    /** @var array<string, PatternTile|null> */
    private array $tiles = [];

    /** @param Closure(Canvas, Element, Style): void $children draws an element's children */
    public function __construct(
        private readonly Context $context,
        private readonly Closure $children,
    ) {}

    /**
     * The tile for a <pattern> painted over a box, or null when the pattern paints nothing (an
     * empty tile).
     *
     * @throws ParseException for a pattern that paints itself, directly or through other references,
     *                        or a circular href chain
     */
    public function tile(string $id, Element $pattern, Box $bounds): ?PatternTile
    {
        $chain = $this->context->servers->chain($pattern);
        $attrs = [];
        foreach ($chain as $link) {
            foreach ($link->attributes as $name => $value) {
                $attrs[$name] ??= $value;
            }
        }
        $key =
            Units::UserSpaceOnUse === Units::of($attrs['patternUnits'] ?? null, Units::ObjectBoundingBox)
            && Units::UserSpaceOnUse === Units::of($attrs['patternContentUnits'] ?? null, Units::UserSpaceOnUse)
                ? $id
                : implode(':', [$id, $bounds->x, $bounds->y, $bounds->width, $bounds->height]);
        if (! array_key_exists($key, $this->tiles)) {
            $this->context->guard->enter('pattern', $id);

            try {
                $this->tiles[$key] = $this->draw($pattern, $chain, $attrs, $bounds);
            } finally {
                $this->context->guard->leave();
            }
        }

        return $this->tiles[$key];
    }

    /**
     * Maps the children into the tile: through the viewBox, or the bounding box with
     * patternContentUnits="objectBoundingBox".
     *
     * @param array<string, string> $attrs
     */
    private function contentMatrix(array $attrs, Box $tile, Box $bounds): Matrix
    {
        $numbers = Length::numbers($attrs['viewBox'] ?? null);
        $width   = $numbers[2] ?? 0.0;
        $height  = $numbers[3] ?? 0.0;
        if ($width > 0.0 && $height > 0.0) {
            return Viewport::mapping(
                [$numbers[0] ?? 0.0, $numbers[1] ?? 0.0, $width, $height],
                $tile->width,
                $tile->height,
                $attrs['preserveAspectRatio'] ?? null,
            );
        }

        return Units::ObjectBoundingBox === Units::of($attrs['patternContentUnits'] ?? null, Units::UserSpaceOnUse)
            ? Matrix::scaling($bounds->width, $bounds->height)
            : Matrix::identity();
    }

    /**
     * Draws the tile: the first pattern in the chain with children supplies them.
     *
     * @param list<Element>         $chain
     * @param array<string, string> $attrs
     */
    private function draw(Element $pattern, array $chain, array $attrs, Box $bounds): ?PatternTile
    {
        $tile = $this->tileBox($attrs, $bounds);
        if ($tile->width <= 0.0 || $tile->height <= 0.0) {
            return null;
        }
        $source = $pattern;
        foreach ($chain as $link) {
            if ([] === $link->elements()) {
                continue;
            }
            $source = $link;
            break;
        }
        $template = $this->context->document->template(
            new Dimensions($tile->width, $tile->height),
            Origin::BottomLeft,
        );
        $canvas = $template->canvas();
        $canvas->concat($this->contentMatrix($attrs, $tile, $bounds));
        ($this->children)($canvas, $source, Style::root()->derive($source, $this->context->sheet));
        $matrix = Matrix::translation($tile->x, $tile->y)->then(
            TransformParser::parse($attrs['patternTransform'] ?? null),
        );

        return new PatternTile($template, $tile->width, $tile->height, $matrix);
    }

    /**
     * The tile's place and size in user space.
     *
     * @param array<string, string> $attrs
     */
    private function tileBox(array $attrs, Box $bounds): Box
    {
        return Units::of($attrs['patternUnits'] ?? null, Units::ObjectBoundingBox)->region(
            $attrs['x'] ?? '0',
            $attrs['y'] ?? '0',
            $attrs['width'] ?? '0',
            $attrs['height'] ?? '0',
            $bounds,
            $this->context->viewportWidth,
            $this->context->viewportHeight,
        );
    }
}
