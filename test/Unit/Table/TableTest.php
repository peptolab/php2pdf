<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Table;

use Override;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\Page;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Font\StandardFont;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Table\Cell;
use Php2Pdf\Table\CellStyle;
use Php2Pdf\Table\Column;
use Php2Pdf\Table\Padding;
use Php2Pdf\Table\Row;
use Php2Pdf\Table\Table;
use Php2Pdf\Text\Style\TextStyle;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Table layout, planning and painting, driven through Table and Page::fitTable(). */
#[Group('unit')]
final class TableTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;
    private Page $page;
    private TextStyle $body;

    #[Test]
    public function aGroupTooTallForTheBoxIsPlacedRowByRow(): void
    {
        $table = $this->table([Column::pt(100.0)]);
        $table->addRow(['a'], null, 1, 20.0);
        $table->addRow(['b'], null, 1, 20.0);
        $this->page->fitTable($table, new Box(0.0, 0.0, 100.0, 30.0));

        self::assertSame(1, $table->lastFit()?->rowsPlaced);
    }

    #[Test]
    public function aPlaceableSizesAnAutoColumnByItsWidth(): void
    {
        $table = $this->table([Column::auto(), Column::auto()]);
        $table->addRow([Cell::of($this->doc->template(new Dimensions(100.0, 20.0))), null]);
        $this->page->fitTable($table, new Box(0.0, 0.0, 300.0, 500.0));

        self::assertSame([0.0, 300.0, 300.0], $table->lastFit()?->columnEdges);
    }

    #[Test]
    public function aPlaceableWithoutFittingKeepsItsNaturalHeight(): void
    {
        $table = $this->table([Column::pt(200.0)]);
        $table->addRow([Cell::of($this->doc->template(new Dimensions(100.0, 20.0)), Fit::noFit())]);
        $this->page->fitTable($table, new Box(0.0, 0.0, 200.0, 500.0));

        self::assertSame(20.0, $table->lastFit()?->height);
    }

    #[Test]
    public function autoColumnsTooNarrowForTheirWordsShrinkInProportion(): void
    {
        $table = $this->table([Column::auto(), Column::auto()]);
        $table->addRow(['Supercalifragilistic', 'Supercalifragilistic']);
        $this->page->fitTable($table, new Box(0.0, 0.0, 40.0, 500.0));

        self::assertSame([0.0, 20.0, 40.0], $table->lastFit()?->columnEdges);
    }

    #[Test]
    public function exposesItsColumnsAndRows(): void
    {
        $columns = [Column::pt(50.0), Column::auto()];
        $table   = $this->table($columns)
            ->addHeaderRow(['h1', 'h2'])
            ->addRow(['b1', 'b2'])
            ->addFooterRow(['f1', 'f2']);

        self::assertEquals(
            [$columns, [new Row(['h1', 'h2'])], [new Row(['b1', 'b2'])], [new Row(['f1', 'f2'])]],
            [$table->columns(), $table->headerRows(), $table->rows(), $table->footerRows()],
        );
    }

    #[Test]
    public function needsAtLeastOneColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A table needs at least one column.');
        $this->table([]);
    }

    #[Test]
    public function placeableContentIsSkippedWhenThePaddingLeavesNoRoom(): void
    {
        $table = $this->table([Column::pt(10.0)], new CellStyle(padding: Padding::all(6.0)));
        $table->addRow([Cell::of($this->doc->template(new Dimensions(10.0, 10.0)))]);
        $this->page->fitTable($table, new Box(0.0, 0.0, 10.0, 500.0));
        $this->doc->close();

        self::assertStringNotContainsString(' Do', $this->out->contents());
    }

    #[Test]
    public function refusesRowsOnceFitted(): void
    {
        $table = $this->table([Column::pt(100.0)])->addRow(['a']);
        $this->page->fitTable($table, new Box(0.0, 0.0, 100.0, 500.0));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Rows cannot be added once the table has been fitted; reset() first.');
        $table->addRow(['b']);
    }

    #[Test]
    public function reportsAnOverflowWhenRowsAreLeft(): void
    {
        $table = $this->table([Column::pt(100.0)]);
        $table->addRow(['a'], null, null, 20.0);
        $table->addRow(['b'], null, null, 20.0);
        $this->page->fitTable($table, new Box(0.0, 0.0, 100.0, 30.0));

        self::assertTrue($table->lastFit()?->overflowed());
    }

    #[Override]
    protected function setUp(): void
    {
        $this->out = new MemoryOutput();
        $this->doc = new Document($this->out, new DocumentOptions(
            compress: false,
            clock: new FixedClock('2026-01-01T00:00:00Z'),
        ));
        $this->page = $this->doc->addPage();
        $this->body = new TextStyle(
            font: $this->doc->fonts()->standard(StandardFont::Helvetica),
            size: 10.0,
        );
    }

    /** @param list<Column> $columns */
    private function table(array $columns, ?CellStyle $style = null): Table
    {
        return $this->doc->table($columns, $style, $this->body);
    }
}
