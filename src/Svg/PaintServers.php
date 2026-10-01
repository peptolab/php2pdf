<?php

declare(strict_types=1);

namespace PhpPdf\Svg;

use PhpPdf\Color\AbstractGradient;
use PhpPdf\Color\GradientStop;
use PhpPdf\Color\LinearGradient;
use PhpPdf\Color\RadialGradient;
use PhpPdf\Geometry\Box;
use PhpPdf\Geometry\Matrix;

use function array_key_exists;
use function count;
use function ltrim;
use function max;
use function min;
use function strtolower;
use function trim;

/**
 * Resolves gradient elements, following href chains for inherited attributes and stops, into
 * shading gradients with the matrix that maps gradient space into user space.
 *
 * @internal
 */
final class PaintServers
{
    public function __construct(
        private readonly SvgFile $file,
    ) {}

    /**
     * @param array<string, string> $attrs
     * @param list<GradientStop>    $stops
     */
    private static function linear(
        array $attrs,
        float $size,
        float $reference,
        array $stops,
        bool $extend,
    ): LinearGradient {
        return new LinearGradient(
            Length::parse($attrs['x1'] ?? '0%', $size) ?? 0.0,
            Length::parse($attrs['y1'] ?? '0%', $size) ?? 0.0,
            Length::parse($attrs['x2'] ?? '100%', $size) ?? $reference,
            Length::parse($attrs['y2'] ?? '0%', $size) ?? 0.0,
            $stops,
            $extend,
        );
    }

    /**
     * @param array<string, string> $attrs
     * @param list<GradientStop>    $stops
     */
    private static function radial(array $attrs, float $size, array $stops, bool $extend): RadialGradient
    {
        $cx = Length::parse($attrs['cx'] ?? '50%', $size) ?? 0.5;
        $cy = Length::parse($attrs['cy'] ?? '50%', $size) ?? 0.5;
        $r  = Length::parse($attrs['r'] ?? '50%', $size) ?? 0.5;
        $fx = Length::parse($attrs['fx'] ?? null, $size) ?? $cx;
        $fy = Length::parse($attrs['fy'] ?? null, $size) ?? $cy;
        $fr = Length::parse($attrs['fr'] ?? '0', $size) ?? 0.0;

        return new RadialGradient($fx, $fy, $fr, $cx, $cy, max(0.0, $r), $stops, $extend);
    }

    /**
     * The gradient for a paint server id and the box it is painted over, or null when the id is
     * not a usable gradient.
     *
     * @return array{0: AbstractGradient, 1: Matrix}|null
     */
    public function gradient(string $id, Box $bounds, Style $style, StyleSheet $sheet): ?array
    {
        $element = $this->file->byId($id);
        if (null === $element || ('linearGradient' !== $element->name && 'radialGradient' !== $element->name)) {
            return null;
        }
        $chain = $this->chain($element);
        $attrs = [];
        foreach ($chain as $link) {
            foreach ($link->attributes as $name => $value) {
                $attrs[$name] ??= $value;
            }
        }
        $stops = $this->stops($chain, $style, $sheet);
        if ([] === $stops) {
            return null;
        }
        if (1 === count($stops)) {
            $stops[] = new GradientStop(1.0, $stops[0]->color);
        }
        $extend    = 'pad' === strtolower(trim($attrs['spreadMethod'] ?? 'pad'));
        $objectBox = 'userSpaceOnUse' !== trim($attrs['gradientUnits'] ?? 'objectBoundingBox');
        $matrix    = TransformParser::parse($attrs['gradientTransform'] ?? null);
        $reference = $objectBox ? 1.0 : 0.0;
        if ($objectBox) {
            $matrix = $matrix->then(
                new Matrix($bounds->width, 0.0, 0.0, $bounds->height, $bounds->x, $bounds->y),
            );
        }
        $size     = $objectBox ? 1.0 : 100.0;
        $gradient = 'linearGradient' === $element->name
            ? self::linear($attrs, $size, $reference, $stops, $extend)
            : self::radial($attrs, $size, $stops, $extend);

        return [$gradient, $matrix];
    }

    /**
     * The element followed by the gradients it references, nearest first.
     *
     * @return list<Element>
     */
    private function chain(Element $element): array
    {
        $chain = [$element];
        $seen  = [];
        $id    = $element->id();
        if (null !== $id) {
            $seen[$id] = true;
        }
        $current = $element;
        while (count($chain) < 16) {
            $href = $current->href();
            if (null === $href) {
                break;
            }
            $target = ltrim(trim($href), characters: '#');
            if (array_key_exists($target, $seen)) {
                break;
            }
            $next = $this->file->byId($target);
            if (null === $next) {
                break;
            }
            $seen[$target] = true;
            $chain[]       = $next;
            $current       = $next;
        }

        return $chain;
    }

    /**
     * The stops of the first gradient in the chain that has any.
     *
     * @param list<Element> $chain
     *
     * @return list<GradientStop>
     */
    private function stops(array $chain, Style $style, StyleSheet $sheet): array
    {
        foreach ($chain as $gradient) {
            $stops  = [];
            $offset = 0.0;
            foreach ($gradient->elements() as $child) {
                if ('stop' !== $child->name) {
                    continue;
                }
                $stopStyle = $style->derive($child, $sheet);
                $raw       = trim($child->attribute('offset') ?? '0');
                $value     = Length::parse($raw, 1.0) ?? 0.0;
                $offset    = max($offset, min(1.0, max(0.0, $value)));
                $stops[]   = new GradientStop($offset, $stopStyle->stopColor());
            }
            if ([] !== $stops) {
                return $stops;
            }
        }

        return [];
    }
}
