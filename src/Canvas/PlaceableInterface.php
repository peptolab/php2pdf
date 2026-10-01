<?php

declare(strict_types=1);

namespace Php2Pdf\Canvas;

use Php2Pdf\Document\Dimensions;
use Php2Pdf\Geometry\Matrix;

/**
 * Something the fit engine can place: an image, a template, an imported page, SVG artwork, or
 * artwork of your own. Implement it to draw with the canvas directly wherever boxes and fit
 * methods are accepted (Canvas::place(), table cells, Textflow inline artwork).
 *
 * @api
 */
interface PlaceableInterface
{
    /**
     * Draws the object so that its own space (origin bottom-left, y up, points, from 0,0 to the
     * natural size) maps through $matrix. Wrap the drawing in save() and restore() and apply the
     * matrix with concat():
     *
     *     $canvas->save()->concat($matrix);
     *     $canvas->fillRect(0, 0, $size->width, $size->height);
     *     $canvas->restore();
     *
     * @param Dimensions $size the natural size the placement was computed for
     */
    public function drawAt(Canvas $canvas, Matrix $matrix, Dimensions $size): void;

    /**
     * The natural size in points. Images honour an explicit resolution; other objects ignore it.
     */
    public function naturalSize(?float $dpi = null): Dimensions;
}
