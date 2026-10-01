<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Text\Style\TextStyle;

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
