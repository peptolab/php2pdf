<?php

declare(strict_types=1);

namespace PhpPdf\Fit;

use PhpPdf\Geometry\Box;
use PhpPdf\Geometry\Matrix;

use function abs;

/**
 * The result of fitting an object into a box.
 *
 * @api
 */
final readonly class Placement
{
    /**
     * @param Matrix    $matrix maps the object's own space (origin bottom-left, y up, points) to the canvas
     * @param Box       $bounds the axis-aligned area the object occupies on the canvas
     * @param Box|null  $clip   the area to clip to, when the fit method clips
     * @param float     $scaleX the total scale applied to the object's natural width
     * @param float     $scaleY the total scale applied to the object's natural height
     */
    public function __construct(
        public Matrix $matrix,
        public Box $bounds,
        public ?Box $clip,
        public float $scaleX,
        public float $scaleY,
    ) {}

    /** True when the object does not fully fit the box at this placement. */
    public function overflows(Box $box): bool
    {
        $inside = $this->bounds->intersect($box);

        return (
            abs($inside->width - $this->bounds->width) > 1.0e-6
                || abs($inside->height - $this->bounds->height) > 1.0e-6
        );
    }
}
