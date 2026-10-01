<?php

declare(strict_types=1);

namespace PhpPdf\Document;

/**
 * Print production conformance levels a document can be written to.
 *
 * @api
 */
enum PdfX: string
{
    /**
     * ISO 15930-7: PDF 1.6, embedded fonts, an output intent with its ICC profile, trim or art
     * boxes on every page, device-independent RGB (through a DefaultRGB profile), transparency
     * allowed, no annotations in the page area.
     */
    case X4 = 'PDF/X-4';

    public function version(): string
    {
        return '1.6';
    }
}
