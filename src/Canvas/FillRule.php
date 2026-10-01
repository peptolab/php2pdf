<?php

declare(strict_types=1);

namespace Php2Pdf\Canvas;

/**
 * How a path's interior is decided when it self-intersects or nests.
 *
 * @api
 */
enum FillRule
{
    case NonZero;
    case EvenOdd;
}
