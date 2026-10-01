<?php

declare(strict_types=1);

namespace Php2Pdf\Flow;

/**
 * What happened when a Textflow was fitted into a box.
 *
 * @api
 */
enum FlowResult
{
    /** All text has been placed. */
    case Done;

    /** The box is full; more text remains for the next box. */
    case BoxFull;

    /** Nothing fitted: the box is too small for even one line. */
    case BoxEmpty;

    /** A page break was reached; continue on a new page. */
    case NextPage;
}
