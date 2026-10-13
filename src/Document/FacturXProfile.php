<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

/**
 * The profiles of a Factur-X (ZUGFeRD 2) invoice, as its XMP names them, from the least data to
 * the most.
 *
 * @api
 */
enum FacturXProfile: string
{
    case Minimum = 'MINIMUM';

    case BasicWl = 'BASIC WL';

    case Basic = 'BASIC';

    /** The European standard invoice. */
    case En16931 = 'EN 16931';

    case Extended = 'EXTENDED';

    /** The German public sector's profile of EN 16931 (ZUGFeRD 2.1 and later). */
    case XRechnung = 'XRECHNUNG';

    /**
     * How the XML relates to the PDF: the profiles too sparse to be an invoice on their own are
     * data the PDF is drawn from; the others another form of it.
     */
    public function relationship(): AttachmentRelationship
    {
        return match ($this) {
            self::Minimum, self::BasicWl => AttachmentRelationship::Data,
            default => AttachmentRelationship::Alternative,
        };
    }
}
