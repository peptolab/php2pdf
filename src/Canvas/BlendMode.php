<?php

declare(strict_types=1);

namespace Php2Pdf\Canvas;

/** @api */
enum BlendMode: string
{
    case Normal     = 'Normal';
    case Multiply   = 'Multiply';
    case Screen     = 'Screen';
    case Overlay    = 'Overlay';
    case Darken     = 'Darken';
    case Lighten    = 'Lighten';
    case ColorDodge = 'ColorDodge';
    case ColorBurn  = 'ColorBurn';
    case HardLight  = 'HardLight';
    case SoftLight  = 'SoftLight';
    case Difference = 'Difference';
    case Exclusion  = 'Exclusion';
    case Hue        = 'Hue';
    case Saturation = 'Saturation';
    case Color      = 'Color';
    case Luminosity = 'Luminosity';
}
