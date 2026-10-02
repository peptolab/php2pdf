<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

/**
 * Where a measured line ends.
 *
 * @internal
 */
final readonly class LineEnd
{
    /**
     * @param int   $index  the first item not on the line (a glue, penalty or break item when
     *                      breaking there); for a split box, the one after it
     * @param ?Item $hyphen the penalty the line broke at, when it shows a hyphen
     * @param bool  $forced whether the line ends at an explicit break item
     * @param ?Item $head   for a box too wide for any line, split between characters: the part
     *                      on this line, which takes the box's place (the box is $index - 1)
     */
    public function __construct(
        public int $index,
        public ?Item $hyphen,
        public bool $forced,
        public ?Item $head = null,
    ) {}
}
