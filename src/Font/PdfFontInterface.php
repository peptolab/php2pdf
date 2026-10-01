<?php

declare(strict_types=1);

namespace PhpPdf\Font;

use PhpPdf\Core\Object\Reference;
use PhpPdf\Document\Document;

/**
 * A font a document embeds and draws with: what writing text needs on top of the metrics and
 * shaping of FontInterface. Font and CoreFont implement it; fonts come from FontRegistry.
 *
 * @internal
 */
interface PdfFontInterface extends FontInterface
{
    /**
     * The document the font is embedded in.
     */
    public function document(): Document;

    /**
     * The bytes that select these glyphs in a content stream string.
     *
     * @param list<int> $glyphIds
     */
    public function encode(array $glyphIds): string;

    /**
     * Records the glyphs of a run as used.
     */
    public function markUsed(GlyphRun $run): void;

    /** The font dictionary's object. */
    public function reference(): Reference;
}
