<?php

declare(strict_types=1);

namespace Php2Pdf\Canvas;

/** @api */
enum LineCap: int
{
    case Butt   = 0;
    case Round  = 1;
    case Square = 2;
}
