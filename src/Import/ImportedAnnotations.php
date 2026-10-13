<?php

declare(strict_types=1);

namespace Php2Pdf\Import;

/**
 * What becomes of the annotations of imported pages.
 *
 * @api
 */
enum ImportedAnnotations
{
    /** Annotations are left out: only the page's content is placed. */
    case None;

    /**
     * The annotations that print (highlights, stamps, notes' icons, signatures, filled form fields)
     * are drawn into the page as their appearances, wherever it is placed; links to web addresses
     * come back as links where the page is placed on a page. Links within the source document
     * are dropped, as its other pages are not in the output.
     */
    case Flatten;
}
