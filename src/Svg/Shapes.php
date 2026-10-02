<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Geometry\Path;
use Php2Pdf\Geometry\PathBuilder;

use function max;

/**
 * Builds path data for the basic shapes and <path>.
 *
 * @internal
 */
final class Shapes
{
    public static function isShape(string $name): bool
    {
        return match ($name) {
            'rect', 'circle', 'ellipse', 'line', 'polyline', 'polygon', 'path' => true,
            default                                                            => false,
        };
    }

    /** The element's geometry in its own user space, or null for an empty or unknown shape. */
    public static function path(Element $element, Style $style, Context $context): ?Path
    {
        $path = match ($element->name) {
            'rect'     => self::rect($element, $style, $context),
            'circle'   => self::circle($element, $style, $context),
            'ellipse'  => self::ellipse($element, $style, $context),
            'line'     => self::line($element, $style, $context),
            'polyline' => Path::polyline(Length::numbers($element->attribute('points'))),
            'polygon'  => Path::polygon(Length::numbers($element->attribute('points'))),
            'path'     => PathParser::parse($element->attribute('d')),
            default    => null,
        };

        return null === $path || $path->isEmpty() ? null : $path;
    }

    private static function circle(Element $element, Style $style, Context $context): ?Path
    {
        $r = $context->length($element->attribute('r'), 'd', $style) ?? 0.0;
        if ($r <= 0.0) {
            return null;
        }

        return Path::ellipse(
            $context->length($element->attribute('cx'), 'x', $style) ?? 0.0,
            $context->length($element->attribute('cy'), 'y', $style) ?? 0.0,
            $r,
            $r,
        );
    }

    private static function ellipse(Element $element, Style $style, Context $context): ?Path
    {
        $rx = $context->length($element->attribute('rx'), 'x', $style, null);
        $ry = $context->length($element->attribute('ry'), 'y', $style, null);
        $rx ??= $ry;
        $ry ??= $rx;
        if (null === $rx || null === $ry || $rx <= 0.0 || $ry <= 0.0) {
            return null;
        }

        return Path::ellipse(
            $context->length($element->attribute('cx'), 'x', $style) ?? 0.0,
            $context->length($element->attribute('cy'), 'y', $style) ?? 0.0,
            $rx,
            $ry,
        );
    }

    private static function line(Element $element, Style $style, Context $context): Path
    {
        return (new PathBuilder())->moveTo(
            $context->length($element->attribute('x1'), 'x', $style) ?? 0.0,
            $context->length($element->attribute('y1'), 'y', $style) ?? 0.0,
        )
            ->lineTo(
                $context->length($element->attribute('x2'), 'x', $style) ?? 0.0,
                $context->length($element->attribute('y2'), 'y', $style) ?? 0.0,
            )
            ->build();
    }

    private static function rect(Element $element, Style $style, Context $context): ?Path
    {
        $w = $context->length($element->attribute('width'), 'x', $style) ?? 0.0;
        $h = $context->length($element->attribute('height'), 'y', $style) ?? 0.0;
        if ($w <= 0.0 || $h <= 0.0) {
            return null;
        }
        $rx = $context->length($element->attribute('rx'), 'x', $style, null);
        $ry = $context->length($element->attribute('ry'), 'y', $style, null);
        $rx ??= $ry ?? 0.0;
        $ry ??= $rx;

        return Path::rect(
            $context->length($element->attribute('x'), 'x', $style) ?? 0.0,
            $context->length($element->attribute('y'), 'y', $style) ?? 0.0,
            $w,
            $h,
            max(0.0, $rx),
            max(0.0, $ry),
        );
    }
}
