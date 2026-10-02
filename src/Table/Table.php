<?php

declare(strict_types=1);

namespace Php2Pdf\Table;

use Closure;
use Override;
use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Canvas\PlaceableInterface;
use Php2Pdf\Document\Document;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Flow\FlowableInterface;
use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\Textflow;

use function array_sum;
use function count;
use function sprintf;

/**
 * Rows and columns of cells that flow through boxes across pages: column widths fixed, as
 * percentages or sized from content; cells holding text, Textflows or placeables with column
 * and row spans, padding, borders and backgrounds; header rows repeated at the top of every box
 * and footer rows after its last row, optionally filled per box; rows kept
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

    /** @var list<Row> */
    private array $footers = [];

    /** @var (Closure(FooterContext): (list<Row|list<Cell|string|Textflow|PlaceableInterface|null>>|null))|null */
    private ?Closure $footerCallback = null;

    private ?Grid $footerGrid = null;

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
     * Adds a footer row, drawn after the last body row in every box the table flows through. Its
     * height is reserved in every box.
     *
     * @param Row|list<Cell|string|Textflow|PlaceableInterface|null> $cells
     */
    public function addFooterRow(Row|array $cells, ?CellStyle $style = null, float $minHeight = 0.0): self
    {
        $this->assertNotStarted();
        $this->footers[] = $cells instanceof Row ? $cells : new Row($cells, $style, null, $minHeight);

        return $this;
    }

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
     * Places as many of the remaining rows as fit into a box on any canvas (a page's, a
     * template's), headers first. Page::fitTable() does the same on a page.
     */
    #[Override]
    public function fitInto(Canvas $canvas, Box $box): FlowResult
    {
        [$headers, $body, $footers] = $this->grids($canvas->document());
        // Header and footer rows are drawn afresh in every box.
        $headers->reset();
        $footers->reset();
        $layout   = $this->layout($headers, $body, $footers, $box->width);
        $painter  = new Painter($canvas, $layout, $box);
        $planner  = new Planner($layout, $headers, $body, $this->options, $footers);
        $firstRow = $this->nextRow;
        $plan     = $planner->plan(
            $box->height,
            $this->nextRow,
            $this->continuing,
        );
        if (FlowResult::BoxEmpty === $plan->result) {
            $this->last = new TableFitInfo(FlowResult::BoxEmpty, 0.0, 0, [], []);

            return FlowResult::BoxEmpty;
        }
        $boxFooters = $this->boxFooters($footers, $firstRow, $plan, $canvas->document());
        $edges      = [$painter->edgeY(0.0)];
        $top        = 0.0;
        $placed     = 0;
        $textLeft   = false;
        foreach ($plan->rows as [$grid, $row, $height, $limit]) {
            $isBody  = $grid === $body;
            $heights = match ($grid) {
                $body    => $plan->bodyHeights,
                $footers => $plan->footerHeights,
                default  => $plan->headerHeights,
            };
            $drawn = $grid === $footers ? $boxFooters : $grid;
            $painter->row($drawn->rows[$row] ?? [], $top, $heights, $row, $grid === $footers ? $height : $limit);
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
    public function footerRows(): array
    {
        return $this->footers;
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
     * The height the remaining rows (and the headers and footers) would take at the given width,
     * without placing anything.
     */
    public function measure(Document $document, float $width): float
    {
        [$headers, $body, $footers] = $this->grids($document);
        $headers->reset();
        $footers->reset();
        $layout = $this->layout($headers, $body, $footers, $width);
        $height = array_sum($layout->rowHeights($headers, 0, $headers->count() - 1));
        $height += array_sum($layout->rowHeights($footers, 0, $footers->count() - 1));
        if ($this->nextRow < $body->count()) {
            $height += array_sum($layout->rowHeights($body, $this->nextRow, $body->count() - 1));
        }

        return $height;
    }

    /**
     * Fills the footer rows per box, for subtotals carried forward. The callback is told which body
     * rows the box holds and returns rows to draw in place of those added with addFooterRow(), one
     * for each, or null to draw those. Its rows are laid out at the heights reserved for the
     * footer rows, so give the footer rows the content (or minimum height) the tallest needs.
     *
     * @param (Closure(FooterContext): (list<Row|list<Cell|string|Textflow|PlaceableInterface|null>>|null))|null $callback
     */
    public function onFooter(?Closure $callback): self
    {
        $this->footerCallback = $callback;

        return $this;
    }

    #[Override]
    public function reset(): void
    {
        $this->headerGrid?->reset();
        $this->bodyGrid?->reset();
        $this->footerGrid?->reset();
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

    /**
     * The footer rows to draw in this box: the callback's, when it gives any, or the footer grid.
     */
    private function boxFooters(Grid $footers, int $firstRow, Plan $plan, Document $document): Grid
    {
        if (null === $this->footerCallback || [] === $plan->footerHeights || null === $plan->lastBodyRow) {
            return $footers;
        }
        $rows = ($this->footerCallback)(new FooterContext(
            $firstRow,
            $plan->lastBodyRow,
            FlowResult::Done === $plan->result,
        ));
        if (null === $rows) {
            return $footers;
        }
        if (count($rows) !== $footers->count()) {
            throw new InvalidArgumentException(sprintf(
                'The footer callback returned %d rows for a table with %d footer rows.',
                count($rows),
                $footers->count(),
            ));
        }
        $built = [];
        foreach ($rows as $row) {
            $built[] = $row instanceof Row ? $row : new Row($row);
        }

        return new Grid($built, $this->columns, $this->style, $this->text, $document);
    }

    /** @return array{0: Grid, 1: Grid, 2: Grid} */
    private function grids(Document $document): array
    {
        if (null !== $this->document && $this->document !== $document) {
            throw new InvalidArgumentException('A table belongs to the document it was first fitted into.');
        }
        $this->document   ??= $document;
        $this->headerGrid ??= new Grid($this->headers, $this->columns, $this->style, $this->text, $document);
        $this->bodyGrid   ??= new Grid($this->rows, $this->columns, $this->style, $this->text, $document);
        $this->footerGrid ??= new Grid($this->footers, $this->columns, $this->style, $this->text, $document);

        return [$this->headerGrid, $this->bodyGrid, $this->footerGrid];
    }

    /** The layout for a box width, sizing auto columns from the whole table's content once. */
    private function layout(Grid $headers, Grid $body, Grid $footers, float $width): Layout
    {
        $layout = new Layout(
            $this->columns,
            [$headers, $body, $footers],
            $width,
            $this->options,
            $this->contentWidths,
        );
        $this->contentWidths ??= $layout->contentWidths;

        return $layout;
    }
}
