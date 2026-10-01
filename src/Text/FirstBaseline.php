<?php

declare(strict_types=1);

namespace Php2Pdf\Text;

/**
 * How far the first baseline sits below the top of the box.
 *
 * @api
 */
enum FirstBaseline
{
    /** The first line's ascender touches the top, as InDesign does by default. */
    case Ascender;

    /** The cap height touches the top. */
    case CapHeight;

    /** The x-height touches the top. */
    case XHeight;

    /** One full line of leading, as PDFlib does by default. */
    case Leading;
}
