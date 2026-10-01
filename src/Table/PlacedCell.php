<?php

declare(strict_types=1);

namespace PhpPdf\Table;

use PhpPdf\Text\Style\TextStyle;
use PhpPdf\Text\Textflow;

/**
 * A cell placed on the grid with its resolved style and, for text, the Textflow that carries
 * its content across boxes.
 *
 * @internal
 */
final class PlacedCell
{
    /** Whether placeable content has been drawn (it is drawn once when a row splits). */
    public bool $drawn = false;

    public function __construct(
        public readonly Cell $cell,
        public readonly int $row,
        public readonly int $column,
        public readonly CellStyle $style,
        public readonly TextStyle $textStyle,
        public readonly ?Textflow $flow,
    ) {}

    public function lastColumn(): int
    {
        return $this->column + $this->cell->colspan - 1;
    }

    public function lastRow(): int
    {
        return $this->row + $this->cell->rowspan - 1;
    }

    public function padding(): Padding
    {
        return $this->style->padding ?? Padding::none();
    }
}
