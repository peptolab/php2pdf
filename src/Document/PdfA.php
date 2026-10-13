<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

/**
 * Archival conformance levels a document can be written to (ISO 19005), at level B: what is seen
 * is preserved. They need an output intent before colour is drawn and embedded fonts, as PDF/X
 * does, and can be combined with it.
 *
 * @api
 */
enum PdfA: string
{
    /** ISO 19005-2: PDF 1.7 features, transparency and layers allowed, no attachments. */
    case A2b = 'PDF/A-2b';

    /**
     * ISO 19005-3: PDF/A-2 with files of any kind attached, each with its relationship to the
     * document, as ZUGFeRD and Factur-X invoices carry their XML.
     */
    case A3b = 'PDF/A-3b';

    /** The part of ISO 19005, as XMP declares it. */
    public function part(): int
    {
        return match ($this) {
            self::A2b => 2,
            self::A3b => 3,
        };
    }
}
