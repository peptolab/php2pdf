<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Style;

/**
 * How a paragraph is broken into lines, as InDesign's composers do it.
 *
 * @api
 */
enum Composer
{
    /**
     * The breaks of the whole paragraph are chosen together, so its lines are evenly spaced
     * (Knuth and Plass's algorithm): the default for justified text.
     */
    case Paragraph;

    /** Each line is filled in turn with as much as fits: the default otherwise. */
    case SingleLine;
}
