<?php

declare(strict_types=1);

namespace PhpPdf\Document;

use PhpPdf\Color\IccProfile;
use PhpPdf\Core\ClockInterface;
use PhpPdf\Core\SystemClock;
use PhpPdf\Font\GlyphCheck;

/**
 * Options fixed for the lifetime of a document.
 *
 * @api
 */
final readonly class DocumentOptions
{
    public ClockInterface $clock;
    public string $version;
    public GlyphCheck $glyphCheck;

    /**
     * @param PdfX|null       $pdfx       write to a PDF/X conformance level: sets the PDF version and
     *                                    checks the document when it closes
     * @param IccProfile|null $rgbProfile the profile RGB colours are interpreted in (DefaultRGB), which
     *                                    PDF/X needs before any RGB colour or image is used
     * @param GlyphCheck|null $glyphCheck what to do with characters a font has no glyph for: Error under
     *                                    PDF/X and Warn otherwise, unless set
     */
    public function __construct(
        string $version = '1.7',
        public bool $compress = true,
        public int $compressionLevel = 6,
        ?ClockInterface $clock = null,
        public string $producer = 'PhpPdf',
        public bool $xmp = true,
        /** Use a top-left origin with y growing downwards on every page unless a page says otherwise. */
        public bool $topDown = false,
        public ?PdfX $pdfx = null,
        public ?IccProfile $rgbProfile = null,
        ?GlyphCheck $glyphCheck = null,
    ) {
        $this->clock      = $clock ?? new SystemClock();
        $this->version    = null === $pdfx ? $version : $pdfx->version();
        $this->glyphCheck = $glyphCheck ?? (null === $pdfx ? GlyphCheck::Warn : GlyphCheck::Error);
    }
}
