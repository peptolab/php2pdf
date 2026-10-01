<?php

declare(strict_types=1);

namespace PhpPdf\Text\Flow;

use PhpPdf\Font\GlyphRun;
use PhpPdf\Text\Style\TextStyle;

/**
 * A positioned run of one style on a line, x measured from the line's left edge.
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
    ) {}
}
