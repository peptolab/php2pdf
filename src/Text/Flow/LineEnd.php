<?php

declare(strict_types=1);

namespace PhpPdf\Text\Flow;

/**
 * Where a measured line ends.
 *
 * @internal
 */
final readonly class LineEnd
{
    /**
     * @param int   $index   the first item not on the line (a glue, penalty or break item when breaking there)
     * @param ?Item $hyphen  the penalty the line broke at, when it shows a hyphen
     * @param bool  $forced  whether the line ends at an explicit break item
     * @param int   $splitAt the index of a box that was split to fit, or -1
     */
    public function __construct(
        public int $index,
        public ?Item $hyphen,
        public bool $forced,
        public int $splitAt = -1,
    ) {}
}
