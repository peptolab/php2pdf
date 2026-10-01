<?php

declare(strict_types=1);

namespace PhpPdf\Canvas;

/** @api */
enum LineJoin: int
{
    case Miter = 0;
    case Round = 1;
    case Bevel = 2;
}
