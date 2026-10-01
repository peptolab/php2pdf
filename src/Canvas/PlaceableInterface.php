<?php

declare(strict_types=1);

namespace PhpPdf\Canvas;

use PhpPdf\Document\Dimensions;
use PhpPdf\Geometry\Matrix;

/**
 * Something the fit engine can place: an image, a template, an imported page.
 *
 * @api
 */
interface PlaceableInterface
{
    /**
     * Draws the object so that its own space (origin bottom-left, y up, points) maps through $matrix.
     *
     * @internal
     *
     * @param Dimensions $size the natural size the placement was computed for
     */
    public function drawAt(Canvas $canvas, Matrix $matrix, Dimensions $size): void;

    /**
     * The natural size in points. Images honour an explicit resolution; other objects ignore it.
     */
    public function naturalSize(?float $dpi = null): Dimensions;
}
