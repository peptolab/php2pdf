<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

/**
 * How an attached file relates to the document (/AFRelationship, ISO 32000-2, 14.13), which
 * PDF/A-3 needs for every attachment.
 *
 * @api
 */
enum AttachmentRelationship: string
{
    /** The file the document was made from. */
    case Source = 'Source';

    /** Data the document's content is drawn from, such as a table or chart's figures. */
    case Data = 'Data';

    /** Another form of the document's content, such as an invoice's XML. */
    case Alternative = 'Alternative';

    /** A file that adds to the document's content. */
    case Supplement = 'Supplement';

    /** A relationship not otherwise named. */
    case Unspecified = 'Unspecified';
}
