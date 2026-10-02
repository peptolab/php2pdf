<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Geometry\Matrix;
use Php2Pdf\Template\Template;

/**
 * A pattern's tile: the template drawn once, the steps it repeats at, and the matrix that maps
 * it into user space.
 *
 * @internal
 */
final readonly class PatternTile
{
    public function __construct(
        public Template $template,
        public float $xStep,
        public float $yStep,
        public Matrix $matrix,
    ) {}
}
