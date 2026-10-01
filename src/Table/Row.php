<?php

declare(strict_types=1);

namespace PhpPdf\Table;

use PhpPdf\Canvas\PlaceableInterface;
use PhpPdf\Exception\InvalidArgumentException;
use PhpPdf\Text\Textflow;

/**
 * A table row: its cells in column order (a cell may be given as a string, a Textflow, a
 * placeable or null for an empty cell), a style every cell inherits, an optional group id that
 * keeps consecutive rows together across boxes, and a minimum height.
 *
 * @api
 */
final readonly class Row
{
    /** @var list<Cell> */
    public array $cells;

    /** @param list<Cell|string|Textflow|PlaceableInterface|null> $cells */
    public function __construct(
        array $cells,
        public ?CellStyle $style = null,
        public ?int $group = null,
        public float $minHeight = 0.0,
    ) {
        if ($minHeight < 0.0) {
            throw new InvalidArgumentException('A row height cannot be negative.');
        }
        $normalised = [];
        foreach ($cells as $cell) {
            $normalised[] = match (true) {
                $cell instanceof Cell => $cell,
                null === $cell => Cell::empty(),
                default => new Cell($cell),
            };
        }
        $this->cells = $normalised;
    }
}
