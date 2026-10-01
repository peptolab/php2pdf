<?php

declare(strict_types=1);

namespace Php2Pdf\Color;

use Php2Pdf\Exception\InvalidArgumentException;

/**
 * A colour at an offset from 0.0 to 1.0 along a gradient.
 *
 * @api
 */
final readonly class GradientStop
{
    public function __construct(
        public float $offset,
        public Gray|Rgb|Cmyk $color,
    ) {
        if ($offset < 0.0 || $offset > 1.0) {
            throw new InvalidArgumentException('Gradient stop offset must be between 0 and 1.');
        }
    }
}
