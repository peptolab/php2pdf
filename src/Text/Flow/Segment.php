<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Canvas\RunPieces;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Text\Style\TextStyle;

/**
 * A positioned run of one style on a line, x measured from the line's left edge, its glyphs
 * scaled horizontally by $scale on top of the style's own scale when justification scales them.
 * A segment set from items keeps the runs its glyphs were joined from, $pieces, so it can be
 * drawn from what is known of each run.
 *
 * @internal
 */
final readonly class Segment
{
    public function __construct(
        public float $x,
        public float $width,
        public TextStyle $style,
        public GlyphRun $run,
        public float $scale = 1.0,
        public RunPieces $pieces = new RunPieces(),
    ) {}
}
