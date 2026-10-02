<?php

declare(strict_types=1);

namespace Php2Pdf\Canvas;

use Php2Pdf\Geometry\Box;

/**
 * Where a fitted text line ended up.
 *
 * @api
 */
final readonly class TextlinePlacement
{
    /**
     * @param Box   $bounds    the area the line occupies, from descender to ascender
     * @param float $baselineX the start of the baseline on the canvas
     * @param float $baselineY the baseline on the canvas
     * @param float $fontSize  the effective size after any scaling
     * @param float $width     the width of the text as drawn
     * @param bool  $fits      whether the line lies entirely inside the box
     */
    public function __construct(
        public Box $bounds,
        public float $baselineX,
        public float $baselineY,
        public float $fontSize,
        public float $width,
        public bool $fits,
    ) {}
}
