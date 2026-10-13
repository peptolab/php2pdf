<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

/**
 * Writes a tagged PDF: its content marked as the elements of a logical structure that assistive
 * technology reads, in reading order, and everything else as artifacts. Textflows tag their
 * paragraphs, tables their rows and cells, links their annotations, and placed artwork with
 * alternate text is a figure; Canvas::tag() tags anything else.
 *
 * @api
 */
final readonly class Accessibility
{
    /**
     * @param string|null $language the document's natural language, as a BCP 47 tag such as
     *                              "en-GB", which PDF/UA needs
     * @param bool        $pdfUa    write to PDF/UA-1 (ISO 14289-1): the language, a title shown
     *                              in place of the file name, embedded fonts and a description
     *                              for every link, checked when the document closes
     */
    public function __construct(
        public ?string $language = null,
        public bool $pdfUa = false,
    ) {}
}
