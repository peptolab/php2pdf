<?php

declare(strict_types=1);

namespace Php2Pdf\Text;

/**
 * How text given to a Textflow is read.
 *
 * @api
 */
enum TextFormat
{
    /** Inline markup (<p>, <b>, <br>…, entities); unknown tags are dropped. Escape data with Markup::escape(). */
    case Markup;

    /** Text exactly as given: blank lines separate paragraphs, single line ends break lines. */
    case Plain;

    /** Markup in which an unknown tag is an error rather than being dropped. */
    case StrictMarkup;
}
