<?php

declare(strict_types=1);

namespace PhpPdf\Font;

/** @api */
enum TextRenderMode: int
{
    case Fill              = 0;
    case Stroke            = 1;
    case FillAndStroke     = 2;
    case Invisible         = 3;
    case FillAndClip       = 4;
    case StrokeAndClip     = 5;
    case FillStrokeAndClip = 6;
    case Clip              = 7;
}
