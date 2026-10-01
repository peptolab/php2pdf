<?php

declare(strict_types=1);

namespace PhpPdf\Text\Flow;

use PhpPdf\Text\Style\TextStyle;

/**
 * A broken line: its segments positioned for alignment, and its vertical metrics.
 *
 * @internal
 */
final readonly class Line
{
    /**
     * @param list<Segment> $segments
     * @param float         $x        the line's left edge from the box's left edge
     */
    public function __construct(
        public array $segments,
        public float $x,
        public float $width,
        public float $ascent,
        public float $descent,
        public float $capHeight,
        public float $xHeight,
        public float $lineHeight,
        public TextStyle $style,
        public int $itemsConsumed,
        public bool $last,
    ) {}
}
