<?php

declare(strict_types=1);

namespace PhpPdf\Color;

/**
 * What a gradient does beyond its end points: keeps the end colours (pad), starts again (repeat),
 * or runs back and forth (reflect), as SVG's spreadMethod and CSS repeating gradients.
 *
 * @api
 */
enum GradientSpread
{
    case Pad;
    case Repeat;
    case Reflect;
}
