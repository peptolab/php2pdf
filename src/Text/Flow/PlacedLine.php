<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

/**
 * A line placed in a box: its baseline's distance from the top of the box, and the paragraph it
 * belongs to.
 *
 * @internal
 */
final readonly class PlacedLine
{
    public function __construct(
        public Line $line,
        public float $baseline,
        public int $paragraph,
    ) {}
}
