<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use Php2Pdf\Color\Rgb;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Objects;

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
     * The bytes that select these glyphs in a content stream string.
     *
     * @param list<int> $glyphIds
     */
    public function encode(array $glyphIds): string;

    /**
     * Records the glyphs of a run as used, and whether the font's ToUnicode map gives back the
     * run's text; when it does not, the run's text is written with it as ActualText.
     */
    public function markUsed(GlyphRun $run): bool;

    /** The document objects the font is embedded with. */
    public function objects(): Objects;

    /** The font dictionary's object. */
    public function reference(): Reference;
}
