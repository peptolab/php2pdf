<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Matrix;

use function trim;

/**
 * The coordinates of a clip path, mask, pattern or gradient: user space, or fractions of the
 * bounding box of the element it applies to.
 *
 * @internal
 */
enum Units
{
    case UserSpaceOnUse;
    case ObjectBoundingBox;

    /** The units an attribute names, or the property's default when it names none. */
    public static function of(?string $value, self $default): self
    {
        return match (trim($value ?? '')) {
            'userSpaceOnUse'    => self::UserSpaceOnUse,
            'objectBoundingBox' => self::ObjectBoundingBox,
            default             => $default,
        };
    }

    /** The matrix that maps content in these units into user space over a bounding box. */
    public function boxMatrix(Box $bounds): Matrix
    {
        return self::ObjectBoundingBox === $this
            ? new Matrix($bounds->width, 0.0, 0.0, $bounds->height, $bounds->x, $bounds->y)
            : Matrix::identity();
    }

    /**
     * A region given by x, y, width and height in these units: lengths against the viewport in
     * user space, fractions of the bounding box otherwise.
     */
    public function region(
        string $x,
        string $y,
        string $width,
        string $height,
        Box $bounds,
        float $viewWidth,
        float $viewHeight,
    ): Box {
        if (self::UserSpaceOnUse === $this) {
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
