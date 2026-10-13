<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

use Php2Pdf\Color\IccProfile;
use Php2Pdf\Core\ClockInterface;
use Php2Pdf\Core\Limits;
use Php2Pdf\Core\SystemClock;
use Php2Pdf\Font\ExternalShaperInterface;
use Php2Pdf\Font\GlyphCheck;
use Php2Pdf\Font\ScriptCheck;
use Php2Pdf\Image\ImageDecoders;

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
    public ScriptCheck $scriptCheck;
    public Limits $limits;

    /** The decoders the document reads images with: ImageDecoders::default() unless set. */
    public ImageDecoders $imageDecoders;

    /**
     * @param PdfX|null       $pdfx       write to a PDF/X conformance level: sets the PDF version and
     *                                    checks the document when it closes
     * @param PdfA|null       $pdfa       write to a PDF/A conformance level, alone or with PDF/X: an
     *                                    output intent, embedded fonts and the document checked when
     *                                    it closes; sets PDF 1.7 unless PDF/X sets 1.6
     * @param IccProfile|null $rgbProfile the profile RGB colours are interpreted in (DefaultRGB), which
     *                                    PDF/X needs before any RGB colour or image is used
     * @param GlyphCheck|null $glyphCheck what to do with characters a font has no glyph for: Error under
     *                                    PDF/X or PDF/A and Warn otherwise, unless set
     * @param Limits|null     $limits     resource limits for the PDFs, images and SVG the document reads
     * @param ScriptCheck|null $scriptCheck what to do with text in a script that needs shaping the
     *                                     library cannot do yet: Error unless set; not consulted
     *                                     when a complex shaper is set
     */
    public function __construct(
        string $version = '1.7',
        public bool $compress = true,
        public int $compressionLevel = 6,
        ?ClockInterface $clock = null,
        public string $producer = 'Php2Pdf',
        public bool $xmp = true,
        /** Use a top-left origin with y growing downwards on every page unless a page says otherwise. */
        public bool $topDown = false,
        public ?PdfX $pdfx = null,
        public ?IccProfile $rgbProfile = null,
        ?GlyphCheck $glyphCheck = null,
        ?Limits $limits = null,
        ?ScriptCheck $scriptCheck = null,
        /** Shapes the scripts the font engine does not (Indic, South East Asian, Tibetan...); see HarfBuzz::detect(). */
        public ?ExternalShaperInterface $complexShaper = null,
        ?ImageDecoders $imageDecoders = null,
        /**
         * Write objects other than streams a hundred at a time in compressed object streams, and
         * the cross-reference as a stream: smaller files. Only for compressed output of PDF 1.5
         * and later; uncompressed output keeps every object readable on its own.
         */
        public bool $objectStreams = true,
        public ?PdfA $pdfa = null,
    ) {
        $this->imageDecoders = $imageDecoders ?? ImageDecoders::default();
        $this->limits        = $limits ?? Limits::default();
        $this->clock         = $clock ?? new SystemClock();
        $this->version       = match (true) {
            null !== $pdfx => $pdfx->version(),
            null !== $pdfa => '1.7',
            default => $version,
        };
        $this->glyphCheck  = $glyphCheck ?? (null === $pdfx && null === $pdfa ? GlyphCheck::Warn : GlyphCheck::Error);
        $this->scriptCheck = $scriptCheck ?? ScriptCheck::Error;
    }

    /**
     * The standards the document conforms to, as error messages name them: "PDF/X-4", "PDF/A-2b",
     * both joined, or null for neither.
     */
    public function conformance(): ?string
    {
        return match (true) {
            null !== $this->pdfx && null !== $this->pdfa => "{$this->pdfx->value} and {$this->pdfa->value}",
            null !== $this->pdfx => $this->pdfx->value,
            null !== $this->pdfa => $this->pdfa->value,
            default => null,
        };
    }
}
