<?php

declare(strict_types=1);

namespace PhpPdf\Svg;

use PhpPdf\Geometry\Matrix;

use function count;
use function preg_match_all;
use function strtolower;

/**
 * Parses an SVG transform list into a matrix. Later transforms in the list apply first, as in
 * SVG.
 *
 * @internal
 */
final class TransformParser
{
    public static function parse(?string $value): Matrix
    {
        $result = Matrix::identity();
        if (null === $value) {
            return $result;
        }
        $m     = [];
        $found = preg_match_all('/([a-zA-Z]+)\s*\(([^)]*)\)/', $value, $m);
        if (false === $found || 0 === $found) {
            return $result;
        }
        $arguments = $m[2] ?? [];
        foreach ($m[1] ?? [] as $i => $name) {
            $args   = Length::numbers($arguments[$i] ?? '');
            $matrix = self::single(strtolower($name), $args);
            if (null === $matrix) {
                continue;
            }
            $result = $matrix->then($result);
        }

        return $result;
    }

    /** @param list<float> $a */
    private static function matrix(array $a): ?Matrix
    {
        $a0 = $a[0] ?? null;
        $a1 = $a[1] ?? null;
        $a2 = $a[2] ?? null;
        $a3 = $a[3] ?? null;
        $a4 = $a[4] ?? null;
        $a5 = $a[5] ?? null;
        if (null === $a0 || null === $a1 || null === $a2 || null === $a3 || null === $a4 || null === $a5) {
            return null;
        }

        return new Matrix($a0, $a1, $a2, $a3, $a4, $a5);
    }

    /** @param list<float> $a */
    private static function rotate(array $a): Matrix
    {
        $rotation = Matrix::rotation($a[0] ?? 0.0);
        if (count($a) < 3) {
            return $rotation;
        }
        $cx = $a[1] ?? 0.0;
        $cy = $a[2] ?? 0.0;

        return Matrix::translation(-$cx, -$cy)->then($rotation)->then(Matrix::translation($cx, $cy));
    }

    /** @param list<float> $a */
    private static function single(string $name, array $a): ?Matrix
    {
        return match ($name) {
            'matrix'    => self::matrix($a),
            'translate' => Matrix::translation($a[0] ?? 0.0, $a[1] ?? 0.0),
            'scale'     => Matrix::scaling($a[0] ?? 1.0, $a[1] ?? $a[0] ?? 1.0),
            'rotate'    => self::rotate($a),
            'skewx'     => Matrix::skewing(0.0, $a[0] ?? 0.0),
            'skewy'     => Matrix::skewing($a[0] ?? 0.0, 0.0),
            default     => null,
        };
    }
}
