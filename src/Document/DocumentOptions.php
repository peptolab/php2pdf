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
     * @param IccProfile|null $rgbProfile the profile RGB colours are interpreted in (DefaultRGB), which
     *                                    PDF/X needs before any RGB colour or image is used
     * @param GlyphCheck|null $glyphCheck what to do with characters a font has no glyph for: Error under
     *                                    PDF/X and Warn otherwise, unless set
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
    ) {
        $this->imageDecoders = $imageDecoders ?? ImageDecoders::default();
        $this->limits        = $limits ?? Limits::default();
        $this->clock         = $clock ?? new SystemClock();
        $this->version       = null === $pdfx ? $version : $pdfx->version();
        $this->glyphCheck    = $glyphCheck ?? (null === $pdfx ? GlyphCheck::Warn : GlyphCheck::Error);
        $this->scriptCheck   = $scriptCheck ?? ScriptCheck::Error;
    }
}
