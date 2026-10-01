<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Closure;
use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Document\Dimensions;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Matrix;
use Php2Pdf\Geometry\Origin;
use Php2Pdf\Template\Template;

use function array_key_exists;
use function implode;
use function trim;

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
    /** @var array<string, array{0: Template, 1: float, 2: float, 3: Matrix}|false> */
    private array $tiles = [];

    /** @var array<string, true> patterns whose tiles are being drawn, to break cycles */
    private array $building = [];

    /** @param Closure(Canvas, Element, Style): void $children draws an element's children */
    public function __construct(
        private readonly Context $context,
        private readonly Closure $children,
    ) {}

    /**
     * The tile template, its steps and the matrix mapping it into user space for a pattern id
     * painted over a box: null when the id is not a pattern, false when the pattern paints
     * nothing (an empty tile, or one that refers to itself).
     *
     * @return array{0: Template, 1: float, 2: float, 3: Matrix}|false|null
     */
    public function tile(string $id, Box $bounds): array|false|null
    {
        $pattern = $this->context->file->byId($id);
        if (null === $pattern || 'pattern' !== $pattern->name) {
            return null;
        }
        if (array_key_exists($id, $this->building)) {
            return false;
        }
        $chain = $this->context->servers->chain($pattern);
        $attrs = [];
        foreach ($chain as $link) {
            foreach ($link->attributes as $name => $value) {
                $attrs[$name] ??= $value;
            }
        }
        $key =
            'userSpaceOnUse' === trim($attrs['patternUnits'] ?? '')
            && 'objectBoundingBox' !== trim($attrs['patternContentUnits'] ?? '')
                ? $id
                : implode(':', [$id, $bounds->x, $bounds->y, $bounds->width, $bounds->height]);
        if (! array_key_exists($key, $this->tiles)) {
            $this->building[$id] = true;
            $this->tiles[$key]   = $this->draw($pattern, $chain, $attrs, $bounds);
            unset($this->building[$id]);
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

        return 'objectBoundingBox' === trim($attrs['patternContentUnits'] ?? '')
            ? Matrix::scaling($bounds->width, $bounds->height)
            : Matrix::identity();
    }

    /**
     * Draws the tile: the first pattern in the chain with children supplies them.
     *
     * @param list<Element>         $chain
     * @param array<string, string> $attrs
     *
     * @return array{0: Template, 1: float, 2: float, 3: Matrix}|false
     */
    private function draw(Element $pattern, array $chain, array $attrs, Box $bounds): array|false
    {
        $tile = $this->tileBox($attrs, $bounds);
        if ($tile->width <= 0.0 || $tile->height <= 0.0) {
            return false;
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

        return [$template, $tile->width, $tile->height, $matrix];
    }

    /**
     * The tile's place and size in user space.
     *
     * @param array<string, string> $attrs
     */
    private function tileBox(array $attrs, Box $bounds): Box
    {
        $x      = $attrs['x'] ?? '0';
        $y      = $attrs['y'] ?? '0';
        $width  = $attrs['width'] ?? '0';
        $height = $attrs['height'] ?? '0';
        if ('userSpaceOnUse' === trim($attrs['patternUnits'] ?? '')) {
            $viewWidth  = $this->context->viewportWidth;
            $viewHeight = $this->context->viewportHeight;

            return new Box(
                Length::parse($x, $viewWidth) ?? 0.0,
                Length::parse($y, $viewHeight) ?? 0.0,
                Length::parse($width, $viewWidth) ?? 0.0,
                Length::parse($height, $viewHeight) ?? 0.0,
            );
        }

        return new Box(
            $bounds->x + ((Length::parse($x, 1.0) ?? 0.0) * $bounds->width),
            $bounds->y + ((Length::parse($y, 1.0) ?? 0.0) * $bounds->height),
            (Length::parse($width, 1.0) ?? 0.0) * $bounds->width,
            (Length::parse($height, 1.0) ?? 0.0) * $bounds->height,
        );
    }
}
