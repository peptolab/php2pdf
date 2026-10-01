<?php

declare(strict_types=1);

namespace PhpPdf\Font;

use PhpPdf\Color\Rgb;
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
     * The colour layers of the run's colour glyphs, by position in the run: each layer glyph with
     * its colour (null for the text colour) and opacity.
     *
     * @return array<int, list<array{0: int, 1: Rgb|null, 2: float}>>
     */
    public function colourLayers(GlyphRun $run): array;

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
