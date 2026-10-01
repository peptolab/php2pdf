<?php

declare(strict_types=1);

namespace Php2Pdf\Geometry;

/**
 * Where a page's coordinate origin sits and which way y grows.
 *
 * @api
 */
enum Origin
{
    /** PDF native: origin at the bottom-left corner, y grows upwards. */
    case BottomLeft;

    /** Design-tool style: origin at the top-left corner, y grows downwards. */
    case TopLeft;

    public function isTopDown(): bool
    {
        return self::TopLeft === $this;
    }
}
