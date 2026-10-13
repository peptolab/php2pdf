<?php

declare(strict_types=1);

namespace Php2Pdf\Canvas;

use Php2Pdf\Font\GlyphRun;

/**
 * The runs a run is joined from, one after another, and how it is shown differently from them:
 * by position, the kerning a run's last glyph has instead of its own (a word space stretched by
 * justification), and the word spacing (Tw) the joined run is shown with, which moves the glyphs
 * after each of the font's PdfFontInterface::wordSpaceGlyph().
 *
 * @internal
 */
final readonly class RunPieces
{
    public function __construct(
        /** @var list<GlyphRun> */
        public array $runs = [],
        /** @var array<int, float> */
        public array $lastKernings = [],
        public float $wordSpace = 0.0,
    ) {}
}
