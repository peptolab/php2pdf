<?php

declare(strict_types=1);

namespace PhpPdf\Table;

use PhpPdf\Exception\InvalidArgumentException;

/**
 * How a table flows through boxes.
 *
 * @api
 */
final readonly class TableOptions
{
    /**
     * @param bool  $repeatHeaders header rows are drawn again at the top of every box
     * @param bool  $splitRows     a row too tall for the space left at the top of a box continues
     *                             its text in the next box instead of overflowing
     * @param float $minRowHeight  rows are never shorter than this, in points
     */
    public function __construct(
        public bool $repeatHeaders = true,
        public bool $splitRows = true,
        public float $minRowHeight = 0.0,
    ) {
        if ($minRowHeight < 0.0) {
            throw new InvalidArgumentException('The minimum row height cannot be negative.');
        }
    }
}
