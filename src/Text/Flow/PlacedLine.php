<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

/**
 * A line placed in a box: its baseline's distance from the top of the box, the paragraph it
 * belongs to, and that paragraph again when the line is its first, as a list item's marker is
 * drawn on it.
 *
 * @internal
 */
final readonly class PlacedLine
{
    public function __construct(
        public Line $line,
        public float $baseline,
        public int $paragraph,
        public ?Paragraph $starts = null,
    ) {}
}
