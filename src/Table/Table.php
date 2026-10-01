<?php

declare(strict_types=1);

namespace PhpPdf\Table;

use Override;
use PhpPdf\Canvas\Canvas;
use PhpPdf\Canvas\FlowableInterface;
use PhpPdf\Canvas\PlaceableInterface;
use PhpPdf\Document\Document;
use PhpPdf\Exception\InvalidArgumentException;
use PhpPdf\Geometry\Box;
use PhpPdf\Text\FlowResult;
use PhpPdf\Text\Style\TextStyle;
use PhpPdf\Text\Textflow;

use function array_sum;
use function count;

/**
 * Rows and columns of cells that flow through boxes across pages: column widths fixed, as
 * percentages or sized from content; cells holding text, Textflows or placeables with column
 * and row spans, padding, borders and backgrounds; header rows repeated in every box; rows kept
 * together in groups; and rows that split when they are too tall for the space left.
 *
 * Each fit returns Done, BoxFull or BoxEmpty like a Textflow, and lastFit() tells where the
 * rows and columns landed.
 *
 * @api
 */
final class Table implements FlowableInterface
{
    /** @var list<Column> */
    private readonly array $columns;

    private readonly TableOptions $options;

    /** @var list<Row> */
    private array $headers = [];

    /** @var list<Row> */
    private array $rows = [];

    private ?Grid         $headerGrid = null;
    private ?Grid         $bodyGrid   = null;
    private ?Document     $document   = null;
    private int           $nextRow    = 0;
    private bool          $continuing = false;
    private ?TableFitInfo $last       = null;

    /** @var array{0: array<int, float>, 1: array<int, float>}|null auto column content widths, from the whole table */
    private ?array $contentWidths = null;

    /**
     * @param list<Column>          $columns
     * @param CellStyle|null        $style   the base style of every cell
     * @param TextStyle|string|null $text    the base text style of every text cell
     */
    public function __construct(
        array $columns,
        ?CellStyle $style = null,
        private readonly TextStyle|string|null $text = null,
        ?TableOptions $options = null,
    ) {
        if ([] === $columns) {
            throw new InvalidArgumentException('A table needs at least one column.');
        }
        $this->columns = $columns;
        $this->style   = $style;
        $this->options = $options ?? new TableOptions();
    }

    private readonly ?CellStyle $style;

    /**
     * Adds a header row, repeated at the top of every box the table flows through.
     *
     * @param Row|list<Cell|string|Textflow|PlaceableInterface|null> $cells
     */
    public function addHeaderRow(Row|array $cells, ?CellStyle $style = null, float $minHeight = 0.0): self
    {
        $this->assertNotStarted();
        $this->headers[] = $cells instanceof Row ? $cells : new Row($cells, $style, null, $minHeight);

        return $this;
    }

    /**
     * Adds a body row. Consecutive rows sharing a group id are kept together across boxes.
     *
     * @param Row|list<Cell|string|Textflow|PlaceableInterface|null> $cells
     */
    public function addRow(Row|array $cells, ?CellStyle $style = null, ?int $group = null, float $minHeight = 0.0): self
    {
        $this->assertNotStarted();
        $this->rows[] = $cells instanceof Row ? $cells : new Row($cells, $style, $group, $minHeight);

        return $this;
    }

    /** @return list<Column> */
    public function columns(): array
    {
        return $this->columns;
    }

    /**
     * Places as many of the remaining rows as fit into the box, headers first.
     *
     * @internal use Canvas::fitTable()
     */
    #[Override]
    public function fitInto(Canvas $canvas, Box $box): FlowResult
    {
        [$headers, $body] = $this->grids($canvas->document());
        // Header rows are drawn afresh in every box.
        $headers->reset();
        $layout  = $this->layout($headers, $body, $box->width);
        $painter = new Painter($canvas, $layout, $box);
        $planner = new Planner($layout, $headers, $body, $this->options);
        $plan    = $planner->plan(
            $box->height,
            $this->nextRow,
            $this->continuing,
        );
        if (FlowResult::BoxEmpty === $plan->result) {
            $this->last = new TableFitInfo(FlowResult::BoxEmpty, 0.0, 0, [], []);

            return FlowResult::BoxEmpty;
        }
        $edges    = [$painter->edgeY(0.0)];
        $top      = 0.0;
        $placed   = 0;
        $textLeft = false;
        foreach ($plan->rows as [$grid, $row, $height, $limit]) {
            $isBody  = $grid === $body;
            $heights = $isBody ? $plan->bodyHeights : $plan->headerHeights;
            $painter->row($grid->rows[$row] ?? [], $top, $heights, $row, $limit);
            $top     += $height;
            $edges[] = $painter->edgeY($top);
            if (! $isBody) {
                continue;
            }
            ++$placed;
            $textLeft = $grid->hasTextLeft($row);
        }
        $lastRow          = $plan->lastBodyRow;
        $this->continuing = null !== $lastRow && $textLeft;
        if (null !== $lastRow) {
            $this->nextRow = $this->continuing ? $lastRow : $lastRow + 1;
        }
        $result = $this->nextRow >= count($this->rows) && ! $this->continuing
            ? FlowResult::Done
            : FlowResult::BoxFull;
        $columnEdges = [];
        foreach ($layout->edges as $x) {
            $columnEdges[] = $box->x + $x;
        }
        $this->last = new TableFitInfo($result, $top, $placed, $edges, $columnEdges);

        return $result;
    }

    /** @return list<Row> */
    public function headerRows(): array
    {
        return $this->headers;
    }

    #[Override]
    public function isDone(): bool
    {
        return $this->nextRow >= count($this->rows) && ! $this->continuing;
    }

    /** Everything about the most recent fit, or null before the first. */
    public function lastFit(): ?TableFitInfo
    {
        return $this->last;
    }

    /**
     * The height the remaining rows (and the headers) would take at the given width, without
     * placing anything.
     */
    public function measure(Document $document, float $width): float
    {
        [$headers, $body] = $this->grids($document);
        $headers->reset();
        $layout = $this->layout($headers, $body, $width);
        $height = array_sum($layout->rowHeights($headers, 0, $headers->count() - 1));
        if ($this->nextRow < $body->count()) {
            $height += array_sum($layout->rowHeights($body, $this->nextRow, $body->count() - 1));
        }

        return $height;
    }

    #[Override]
    public function reset(): void
    {
        $this->headerGrid?->reset();
        $this->bodyGrid?->reset();
        $this->nextRow       = 0;
        $this->continuing    = false;
        $this->last          = null;
        $this->contentWidths = null;
    }

    /** @return list<Row> */
    public function rows(): array
    {
        return $this->rows;
    }

    private function assertNotStarted(): void
    {
        if (null !== $this->bodyGrid) {
            throw new InvalidArgumentException('Rows cannot be added once the table has been fitted; reset() first.');
        }
    }

    /** @return array{0: Grid, 1: Grid} */
    private function grids(Document $document): array
    {
        if (null !== $this->document && $this->document !== $document) {
            throw new InvalidArgumentException('A table belongs to the document it was first fitted into.');
        }
        $this->document   ??= $document;
        $this->headerGrid ??= new Grid($this->headers, $this->columns, $this->style, $this->text, $document);
        $this->bodyGrid   ??= new Grid($this->rows, $this->columns, $this->style, $this->text, $document);

        return [$this->headerGrid, $this->bodyGrid];
    }

    /** The layout for a box width, sizing auto columns from the whole table's content once. */
    private function layout(Grid $headers, Grid $body, float $width): Layout
    {
        $layout = new Layout(
            $this->columns,
            [$headers, $body],
            $width,
            $this->options,
            $this->contentWidths,
        );
        $this->contentWidths ??= $layout->contentWidths;

        return $layout;
    }
}
