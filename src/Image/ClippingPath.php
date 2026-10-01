<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Php2Pdf\Canvas\Canvas;

/**
 * A path from a Photoshop image's resources, in the image's unit square (origin bottom-left, y
 * up), made of Bézier knots: each a control point before the anchor, the anchor, and a control
 * point after it.
 *
 * @internal
 */
final readonly class ClippingPath
{
    /**
     * @param list<list<array{0: array{0: float, 1: float}, 1: array{0: float, 1: float}, 2: array{0: float, 1: float}}>> $subpaths
     */
    public function __construct(
        public array $subpaths,
    ) {}

    /** Adds the closed subpaths to the canvas's current path, in unit-square coordinates. */
    public function addTo(Canvas $canvas): void
    {
        foreach ($this->subpaths as $knots) {
            $first    = null;
            $previous = null;
            foreach ($knots as $knot) {
                if (null === $previous) {
                    $canvas->moveTo($knot[1][0], $knot[1][1]);
                    $first = $knot;
                }
                if (null !== $previous) {
                    $canvas->curveTo(
                        $previous[2][0],
                        $previous[2][1],
                        $knot[0][0],
                        $knot[0][1],
                        $knot[1][0],
                        $knot[1][1],
                    );
                }
                $previous = $knot;
            }
            if (null === $first || null === $previous) {
                continue;
            }
            $canvas->curveTo($previous[2][0], $previous[2][1], $first[0][0], $first[0][1], $first[1][0], $first[1][1]);
            $canvas->closePath();
        }
    }
}
