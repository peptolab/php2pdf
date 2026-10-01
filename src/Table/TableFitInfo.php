<?php

declare(strict_types=1);

namespace PhpPdf\Table;

use PhpPdf\Text\FlowResult;

use function count;

/**
 * What the most recent table fit did: the rows placed and where their edges landed, so callers
 * can draw their own rules or continue below the table.
 *
 * @api
 */
final readonly class TableFitInfo
{
    /**
     * @param float       $height      the vertical extent used, from the top of the box
     * @param int         $rowsPlaced  body rows placed (a split row counts in each box)
     * @param list<float> $rowEdges    canvas y of every horizontal edge, top to bottom (headers included)
     * @param list<float> $columnEdges canvas x of every vertical edge, left to right
     */
    public function __construct(
        public FlowResult $result,
        public float $height,
        public int $rowsPlaced,
        public array $rowEdges,
        public array $columnEdges,
    ) {}

    /** The canvas y of the table's last placed edge. */
    public function bottom(): ?float
    {
        $count = count($this->rowEdges);

        return 0 === $count ? null : $this->rowEdges[$count - 1] ?? null;
    }

    public function overflowed(): bool
    {
        return FlowResult::Done !== $this->result;
    }
}
