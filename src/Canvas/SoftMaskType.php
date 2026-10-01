<?php

declare(strict_types=1);

namespace Php2Pdf\Canvas;

/**
 * How a soft mask's artwork sets the opacity of what is drawn under it: by its luminosity (white
 * shows, black hides) or by its own opacity.
 *
 * @api
 */
enum SoftMaskType
{
    case Luminosity;
    case Alpha;
}
