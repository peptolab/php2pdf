<?php

declare(strict_types=1);

namespace PhpPdf\Fit;

/**
 * How an object is sized to its box.
 *
 * @api
 */
enum FitMethod
{
    /** Natural size, positioned in the box; may overflow it. */
    case NoFit;

    /** Natural size, clipped to the box. */
    case Clip;

    /** Scaled uniformly so the whole object fits inside the box. */
    case Meet;

    /** Scaled uniformly so the object covers the whole box, then clipped. */
    case Slice;

    /** Scaled non-uniformly to fill the box exactly. */
    case Entire;

    /** Natural size when it fits, otherwise shrunk uniformly as with Meet. */
    case Auto;
}
