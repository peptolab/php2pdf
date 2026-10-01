<?php

declare(strict_types=1);

namespace PhpPdf\Svg;

use PhpPdf\Geometry\Matrix;

use function count;
use function max;
use function min;
use function preg_split;
use function str_contains;
use function strtolower;
use function substr;
use function trim;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Viewport geometry: intrinsic sizes and the viewBox to viewport mapping with
 * preserveAspectRatio.
 *
 * @internal
 */
final class Viewport
{
    /**
     * The intrinsic size in user units (px) of an <svg> element.
     *
     * @return array{0: float, 1: float}
     */
    public static function intrinsicSize(Element $svg): array
    {
        $viewBox = self::viewBox($svg);
        $width   = self::absolute($svg->attribute('width'));
        $height  = self::absolute($svg->attribute('height'));
        if (null === $viewBox) {
            return [max(1.0e-6, $width ?? 300.0), max(1.0e-6, $height ?? 150.0)];
        }
        $ratio  = $viewBox[2] / max(1.0e-9, $viewBox[3]);
        $width  ??= null === $height ? $viewBox[2] : $height * $ratio;
        $height ??= $width / max(1.0e-9, $ratio);

        return [max(1.0e-6, $width), max(1.0e-6, $height)];
    }

    /**
     * The matrix mapping viewBox coordinates into a viewport of the given size at the origin.
     *
     * @param array{0: float, 1: float, 2: float, 3: float}|null $viewBox
     */
    public static function mapping(?array $viewBox, float $width, float $height, ?string $preserve): Matrix
    {
        if (null === $viewBox || $viewBox[2] <= 0.0 || $viewBox[3] <= 0.0) {
            return Matrix::identity();
        }
        [$vx, $vy, $vw, $vh] = $viewBox;
        $words = preg_split('/\s+/', strtolower(trim($preserve ?? 'xmidymid meet')), flags: PREG_SPLIT_NO_EMPTY);
        $words = false === $words ? [] : $words;
        $align = $words[0] ?? 'xmidymid';
        if ('defer' === $align) {
            $align = $words[1] ?? 'xmidymid';
        }
        $slice = 'slice' === ($words[count($words) - 1] ?? '');
        $sx    = $width / $vw;
        $sy    = $height / $vh;
        if ('none' !== $align) {
            $s  = $slice ? max($sx, $sy) : min($sx, $sy);
            $sx = $s;
            $sy = $s;
        }
        $tx = -$vx * $sx;
        $ty = -$vy * $sy;
        if ('none' !== $align) {
            $tx += self::alignment($align, 'x') * ($width - ($vw * $sx));
            $ty += self::alignment($align, 'y') * ($height - ($vh * $sy));
        }

        return new Matrix($sx, 0.0, 0.0, $sy, $tx, $ty);
    }

    /** @return array{0: float, 1: float, 2: float, 3: float}|null */
    public static function viewBox(Element $element): ?array
    {
        $numbers = Length::numbers($element->attribute('viewBox'));
        $x       = $numbers[0] ?? null;
        $y       = $numbers[1] ?? null;
        $w       = $numbers[2] ?? null;
        $h       = $numbers[3] ?? null;
        if (null === $x || null === $y || null === $w || null === $h || $w <= 0.0 || $h <= 0.0) {
            return null;
        }

        return [$x, $y, $w, $h];
    }

    private static function absolute(?string $value): ?float
    {
        if (null === $value || str_contains($value, '%')) {
            return null;
        }
        $length = Length::parse($value);

        return null === $length || $length <= 0.0 ? null : $length;
    }

    private static function alignment(string $align, string $axis): float
    {
        $part = 'x' === $axis ? substr($align, offset: 0, length: 4) : substr($align, offset: 4, length: 4);

        return match ($part) {
            'xmin', 'ymin' => 0.0,
            'xmax', 'ymax' => 1.0,
            default        => 0.5,
        };
    }
}
