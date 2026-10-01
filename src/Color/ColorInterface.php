<?php

declare(strict_types=1);

namespace PhpPdf\Color;

/**
 * A colour value usable for fills and strokes: Gray, Rgb, Cmyk, Spot or IccColor. The interface
 * is for type hints, not for implementing; the canvas writes only those five. IccColor covers any
 * ICC-based colour space, and Spot any named separation.
 *
 * @api
 */
interface ColorInterface
{
    /** The number of components the colour space has. */
    public function componentCount(): int;

    /** @return list<float> */
    public function components(): array;
}
