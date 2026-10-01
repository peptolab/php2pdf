<?php

declare(strict_types=1);

namespace Php2Pdf\Fit;

use Php2Pdf\Core\Num;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Matrix;
use Php2Pdf\Geometry\Origin;

use function max;
use function min;
use function sprintf;

/**
 * Fits an object of a given natural size into a box. Shared by images, templates and text lines.
 *
 * The object's own space has its origin at the bottom-left with y growing up. The box and the
 * resulting bounds are in the canvas's coordinate mode; on a top-down canvas the matrix includes
 * the flip that keeps the object upright.
 *
 * @api
 */
final class FitEngine
{
    public static function place(float $width, float $height, Box $box, Fit $fit, Origin $origin): Placement
    {
        if ($width <= 0.0 || $height <= 0.0) {
            throw new InvalidArgumentException('Objects need a positive natural size to be placed.');
        }
        if (FitMethod::NoFit !== $fit->method && ($box->width <= 0.0 || $box->height <= 0.0)) {
            throw new InvalidArgumentException(sprintf(
                'Fitting into a %s × %s box needs a positive width and height.',
                Num::format($box->width),
                Num::format($box->height),
            ));
        }
        $userX = $fit->scaleX;
        $userY = $fit->scaleY ?? $fit->scaleX;

        // Size of the rotated, user-scaled object's bounding box.
        $rotation = Matrix::rotation($fit->rotate);
        $natural  = $rotation->applyToBox(new Box(0.0, 0.0, $width * $userX, $height * $userY));
        [$sx, $sy] = self::scaleFactors($fit->method, $natural->width, $natural->height, $box);

        $boundsWidth  = $natural->width * $sx;
        $boundsHeight = $natural->height * $sy;
        $vertical     = $origin->isTopDown() ? 1.0 - $fit->position->vertical : $fit->position->vertical;
        $bounds       = new Box(
            $box->x + (($box->width - $boundsWidth) * $fit->position->horizontal),
            $box->y + (($box->height - $boundsHeight) * $vertical),
            $boundsWidth,
            $boundsHeight,
        );

        // Object space -> (flip on top-down pages) -> user scale -> rotate -> fit -> move into the bounds.
        $matrix = $origin->isTopDown() ? new Matrix(1.0, 0.0, 0.0, -1.0, 0.0, $height) : Matrix::identity();
        $matrix = $matrix->then(Matrix::scaling($userX, $userY))->then($rotation)->then(Matrix::scaling($sx, $sy));
        $placed = $matrix->applyToBox(new Box(0.0, 0.0, $width, $height));
        $matrix = $matrix->then(Matrix::translation($bounds->x - $placed->x, $bounds->y - $placed->y));

        $clips = FitMethod::Clip === $fit->method || FitMethod::Slice === $fit->method;

        return new Placement($matrix, $bounds, $clips ? $box : null, $sx * $userX, $sy * $userY);
    }

    /** @return array{0: float, 1: float} */
    private static function pair(float $value): array
    {
        return [$value, $value];
    }

    /** @return array{0: float, 1: float} */
    private static function scaleFactors(FitMethod $method, float $width, float $height, Box $box): array
    {
        $uniform = min($box->width / $width, $box->height / $height);

        return match ($method) {
            FitMethod::NoFit, FitMethod::Clip => [1.0, 1.0],
            FitMethod::Meet                   => [$uniform, $uniform],
            FitMethod::Slice                  => self::pair(max($box->width / $width, $box->height / $height)),
            FitMethod::Entire                 => [$box->width / $width, $box->height / $height],
            FitMethod::Auto                   => $uniform < 1.0 ? [$uniform, $uniform] : [1.0, 1.0],
        };
    }
}
