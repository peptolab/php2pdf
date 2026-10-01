<?php

declare(strict_types=1);

namespace Php2Pdf\Color;

/**
 * A colour value usable for fills and strokes: Gray, Rgb, Cmyk, Lab, Spot, DeviceN or IccColor.
 * The interface is for type hints, not for implementing; the canvas writes only those seven.
 * IccColor covers any ICC-based colour space, Spot any named separation, and DeviceN any mix of
 * inks.
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
