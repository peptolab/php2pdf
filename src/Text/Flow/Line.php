<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Text\Style\TextStyle;

/**
 * A broken line: its segments positioned for alignment, and its vertical metrics.
 *
 * @internal
 */
final readonly class Line
{
    /**
     * @param list<Segment>                           $segments
     * @param float                                   $x        the line's left edge from the box's left edge
     * @param int                                     $itemsConsumed the items the line takes from its start,
     *                                                                whole
     * @param int                                     $carry    the characters of the item after those that are on
     *                                                          this line and the lines before: the head of a box too
     *                                                          wide for any line; 0 when the line ends between items
     * @param list<array{0: float, 1: InlineObject}> $inlines  artwork in the line, x from the line's left edge
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
        public array $inlines = [],
        public int $carry = 0,
    ) {}
}
