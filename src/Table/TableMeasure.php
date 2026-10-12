<?php

declare(strict_types=1);

namespace Php2Pdf\Table;

/**
 * The height a table's remaining rows need, as far as Table::measureUpTo() went: it stops laying
 * out rows once the height passes its limit, so a table that does not fit is not measured whole.
 *
 * @api
 */
final readonly class TableMeasure
{
    /**
     * @param float $height   headers, footers and the body rows measured; above the limit when incomplete
     * @param bool  $complete whether every remaining body row was measured
     * @param int   $rows     remaining body rows measured
     */
    public function __construct(
        public float $height,
        public bool $complete,
        public int $rows,
    ) {}

    /** Whether the remaining rows fit in the limit that was measured against. */
    public function fits(): bool
    {
        return $this->complete;
    }
}
