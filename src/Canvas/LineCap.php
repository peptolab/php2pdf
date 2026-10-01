<?php

declare(strict_types=1);

namespace PhpPdf\Canvas;

/** @api */
enum LineCap: int
{
    case Butt   = 0;
    case Round  = 1;
    case Square = 2;
}
