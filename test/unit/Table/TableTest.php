<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Table;

use PhpPdf\Color\Gray;
use PhpPdf\Core\FixedClock;
use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\Page;
use PhpPdf\Document\PageSize;
use PhpPdf\Exception\InvalidArgumentException;
use PhpPdf\Exception\PdfException;
use PhpPdf\Font\Font;
use PhpPdf\Geometry\Box;
use PhpPdf\Table\Border;
use PhpPdf\Table\Cell;
use PhpPdf\Table\CellStyle;
use PhpPdf\Table\Column;
use PhpPdf\Table\Padding;
use PhpPdf\Table\Row;
use PhpPdf\Table\Table;
use PhpPdf\Table\TableOptions;
use PhpPdf\Text\FirstBaseline;
use PhpPdf\Text\FlowResult;
use PhpPdf\Text\Style\Alignment;
use PhpPdf\Text\Style\Leading;
use PhpPdf\Text\Style\TextStyle;
use PhpPdf\Text\Textflow;
use PhpPdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_unique;
use function array_values;
use function count;
use function preg_match_all;
use function str_repeat;
use function substr_count;

use const SORT_REGULAR;

final class TableTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;
    private TextStyle $body;

    /** @return array<string, array{0: FirstBaseline}> */
    public static function firstBaselineProvider(): array
    {
        return [
            'ascender'   => [FirstBaseline::Ascender],
            'cap height' => [FirstBaseline::CapHeight],
            'x-height'   => [FirstBaseline::XHeight],
            'leading'    => [FirstBaseline::Leading],
        ];
    }

    #[Test]
    public function anUngroupedRowspanCellGetsTheHeightItNeeds(): void
    {
        $table = new Table([Column::pt(80.0), Column::pt(80.0)], null, $this->body);
        $table->addRow([Cell::text(str_repeat('tall spanning text ', times: 8))->spanning(1, 3), 'a']);
        $table->addRow(['b']);
        $table->addRow(['c']);
        $measured = $table->measure($this->doc, 160.0);
        $this->doc->addPage(PageSize::A4)->fitTable($table, new Box(0.0, 0.0, 160.0, 500.0));

        self::assertEqualsWithDelta($measured, $table->lastFit()->height ?? 0.0, 1.0e-6);
    }

    #[Test]
    public function autoColumnsKeepTheirWidthsInEveryBox(): void
    {
        $table = new Table([Column::auto(), Column::auto()], null, $this->body);
        for ($i = 0; $i < 6; ++$i) {
            $table->addRow([str_repeat('long words here ', times: 3), 'x']);
        }
        for ($i = 0; $i < 6; ++$i) {
            $table->addRow(['x', str_repeat('other long words ', times: 3)]);
        }
        $page  = $this->doc->addPage(PageSize::A4);
        $edges = [];
        do {
            $result  = $page->fitTable($table, new Box(0.0, 0.0, 300.0, 80.0));
            $edges[] = $table->lastFit()?->columnEdges;
        } while (FlowResult::Done !== $result && count($edges) < 20);

        self::assertGreaterThan(2, count($edges));
        self::assertSame([$edges[0]], array_values(array_unique($edges, SORT_REGULAR)));
    }

    #[Test]
    public function cellStyleFirstBaselineOverridesTheTableOption(): void
    {
        $flow  = Textflow::fromText($this->doc, 'a', $this->body);
        $table = new Table(
            [Column::auto(), Column::auto()],
            null,
            $this->body,
            new TableOptions(firstBaseline: FirstBaseline::Ascender),
        );
        $table->addRow([Cell::text('b'), new Cell($flow, style: new CellStyle(firstBaseline: FirstBaseline::Leading))]);
        $this->doc->addPage(PageSize::A4)->fitTable($table, new Box(0.0, 0.0, 200.0, 500.0));
        self::assertEqualsWithDelta(500.0 - 12.0, $flow->lastFit()?->firstBaseline[1] ?? 0.0, 1.0e-6);
        self::assertEqualsWithDelta(
            12.0 - $this->bodyFont()->descender(10.0),
            $table->lastFit()->height ?? 0.0,
            1.0e-6,
        );
    }

    #[Test]
    #[DataProvider('firstBaselineProvider')]
    public function cellTextIsDrawnAtTheTableFirstBaseline(FirstBaseline $mode): void
    {
        $flow  = Textflow::fromText($this->doc, 'a', $this->body);
        $table = new Table(
            [Column::auto()],
            new CellStyle(padding: Padding::all(2.0)),
            $this->body,
            new TableOptions(firstBaseline: $mode),
        );
        $table->addRow([$flow]);
        $this->doc->addPage(PageSize::A4)->fitTable($table, new Box(0.0, 0.0, 200.0, 500.0));
        $baseline = $flow->lastFit()?->firstBaseline;
        self::assertNotNull($baseline);
        self::assertEqualsWithDelta(500.0 - 2.0 - $this->firstBaselineOffset($mode), $baseline[1], 1.0e-6);
    }

    #[Test]
    public function documentFlowDrainsAcrossPages(): void
    {
        $table = new Table([Column::auto()], null, $this->body);
        for ($i = 0; $i < 30; ++$i) {
            $table->addRow(["row {$i}"]);
        }
        $pages = $this->doc->flow(
            $table,
            [new Box(0.0, 0.0, 200.0, 60.0), new Box(0.0, 0.0, 200.0, 120.0)],
            static fn(Document $doc): Page => $doc->addPage(PageSize::A5),
        );
        self::assertSame(1 + 3, $pages);
        self::assertSame(4, $this->doc->pageCount());
    }

    #[Test]
    public function fixedPercentAndAutoColumnsShareTheWidth(): void
    {
        $table = new Table(
            [Column::pt(100.0), Column::percent(25.0), Column::auto(), Column::auto()],
            null,
            $this->body,
        );
        $table->addRow(['a', 'b', 'short', 'a much longer cell text here']);
        $page   = $this->doc->addPage(PageSize::A4);
        $result = $page->fitTable($table, new Box(0.0, 0.0, 400.0, 500.0));
        self::assertSame(FlowResult::Done, $result);
        $info = $table->lastFit();
        self::assertNotNull($info);
        $edges = $info->columnEdges;
        self::assertCount(5, $edges);
        self::assertEqualsWithDelta([0.0, 100.0, 200.0], [$edges[0], $edges[1], $edges[2]], 1.0e-6);
        self::assertEqualsWithDelta(400.0, $edges[4], 1.0e-6);
        self::assertGreaterThan($edges[3] - $edges[2], $edges[4] - $edges[3], 'The longer text gets the wider column.');
    }

    #[Test]
    public function groupsStayTogetherAndATallRowSplits(): void
    {
        $table = new Table([Column::auto()], null, $this->body);
        $table->addRow(['one']);
        $table->addRow(['two']);
        $table->addRow(['total a'], null, 1);
        $table->addRow(['total b'], null, 1);
        $table->addRow(['total c'], null, 1);
        $table->addRow([str_repeat('long text that wraps over many lines. ', times: 40)]);
        $page = $this->doc->addPage(PageSize::A4);
        $box  = new Box(0.0, 0.0, 200.0, 50.0);

        self::assertSame(FlowResult::BoxFull, $page->fitTable($table, $box));
        self::assertSame(
            2,
            $table->lastFit()?->rowsPlaced,
            'The group of three does not fit after two rows, so it waits.',
        );
        self::assertSame(FlowResult::BoxFull, $page->fitTable($table, $box));
        self::assertSame(3, $table->lastFit()?->rowsPlaced, 'The group is placed together.');
        self::assertSame(FlowResult::BoxFull, $page->fitTable($table, $box));
        self::assertSame(1, $table->lastFit()?->rowsPlaced, 'The tall row starts and is cut.');
        self::assertFalse($table->isDone());
        $pages = 0;
        while (FlowResult::Done !== $page->fitTable($table, $box) && $pages < 20) {
            ++$pages;
        }
        self::assertTrue($table->isDone());
        self::assertGreaterThan(2, $pages);
    }

    #[Test]
    public function headersRepeatAndRowsFlowAcrossBoxes(): void
    {
        $table = new Table(
            [Column::auto(), Column::pt(60.0)],
            new CellStyle(padding: Padding::symmetric(2.0, 0.0)),
            $this->body,
        );
        $table->addHeaderRow(['Item', 'Price'], new CellStyle(borderBottom: new Border(0.5)));
        for ($i = 1; $i <= 20; ++$i) {
            $table->addRow(["Row {$i}", '1.00']);
        }
        $page = $this->doc->addPage(PageSize::A4);
        $fits = [];
        do {
            $result = $page->fitTable($table, new Box(0.0, 0.0, 200.0, 100.0));
            $fits[] = $table->lastFit()?->rowsPlaced;
        } while (FlowResult::Done !== $result && count($fits) < 10);
        self::assertSame(FlowResult::Done, $result);
        self::assertTrue($table->isDone());
        // A row is ascender + descender + 4 pt padding (about 15.6 pt): a header and five rows per box.
        self::assertSame([5, 5, 5, 5], $fits);
        $this->doc->close();
    }

    #[Test]
    public function measuresRemainingHeight(): void
    {
        $table = new Table([Column::auto()], new CellStyle(padding: Padding::all(1.0)), $this->body);
        $table->addRow(['a']);
        $table->addRow(['b'], null, null, 30.0);
        $font = $this->body->font;
        self::assertInstanceOf(Font::class, $font);
        $line = $font->ascender(10.0) - $font->descender(10.0);
        self::assertEqualsWithDelta($line + 2.0 + 30.0, $table->measure($this->doc, 200.0), 1.0e-6);
    }

    #[Test]
    #[DataProvider('firstBaselineProvider')]
    public function placedRowsMatchTheirMeasuredHeight(FirstBaseline $mode): void
    {
        $table = new Table([Column::auto()], null, $this->body, new TableOptions(firstBaseline: $mode));
        $table->addRow(['a']);
        $table->addRow(['b']);
        $measured = $table->measure($this->doc, 200.0);
        $this->doc->addPage(PageSize::A4)->fitTable($table, new Box(0.0, 0.0, 200.0, 500.0));
        self::assertEqualsWithDelta($measured, $table->lastFit()->height ?? 0.0, 1.0e-6);
    }

    #[Test]
    public function rejectsColumnsWiderThanTheBox(): void
    {
        $table = new Table([Column::pt(300.0), Column::pt(300.0)], null, $this->body);
        $table->addRow(['a', 'b']);
        $this->expectException(PdfException::class);
        $this->doc->addPage(PageSize::A4)->fitTable($table, new Box(0.0, 0.0, 400.0, 500.0));
    }

    #[Test]
    public function rejectsRowsWithTooManyCells(): void
    {
        $table = new Table([Column::auto()], null, $this->body);
        $table->addRow(['a', 'b']);
        $this->expectException(InvalidArgumentException::class);
        $this->doc->addPage(PageSize::A4)->fitTable($table, new Box(0.0, 0.0, 400.0, 500.0));
    }

    #[Test]
    public function reportsBoxEmptyAndNeverSplitsWhenDisabled(): void
    {
        $table = new Table([Column::auto()], null, $this->body, new TableOptions(splitRows: false));
        $table->addRow([str_repeat('many lines ', times: 100)]);
        $page = $this->doc->addPage(PageSize::A4);
        self::assertSame(FlowResult::BoxEmpty, $page->fitTable($table, new Box(0.0, 0.0, 100.0, 30.0)));
        self::assertFalse($table->isDone());
        self::assertSame(FlowResult::Done, $page->fitTable($table, new Box(0.0, 0.0, 300.0, 800.0)));
    }

    #[Test]
    public function resetMeasuresAutoColumnsAgainFromTheWholeTable(): void
    {
        $table = new Table([Column::auto(), Column::auto()], null, $this->body);
        $table->addRow(['short', str_repeat('a longer cell ', times: 4)]);
        $page = $this->doc->addPage(PageSize::A4);
        $page->fitTable($table, new Box(0.0, 0.0, 300.0, 400.0));
        $first = $table->lastFit()?->columnEdges;
        $table->reset();
        $page->fitTable($table, new Box(0.0, 0.0, 300.0, 400.0));

        self::assertSame($first, $table->lastFit()?->columnEdges);
    }

    #[Test]
    #[DataProvider('firstBaselineProvider')]
    public function rowsAreMeasuredFromTheTableFirstBaseline(FirstBaseline $mode): void
    {
        $table = new Table(
            [Column::auto()],
            new CellStyle(padding: Padding::all(1.0)),
            $this->body,
            new TableOptions(firstBaseline: $mode),
        );
        $table->addRow(['a']);
        $expected = $this->firstBaselineOffset($mode) - $this->bodyFont()->descender(10.0) + 2.0;
        self::assertEqualsWithDelta($expected, $table->measure($this->doc, 200.0), 1.0e-6);
    }

    #[Test]
    public function rowsASpanningCellCoversMoveToTheNextBoxTogether(): void
    {
        $table = new Table([Column::pt(80.0), Column::pt(80.0)], null, $this->body);
        $table->addRow(['first', 'row']);
        $table->addRow([Cell::text('span')->spanning(1, 3), 'b']);
        $table->addRow(['c']);
        $table->addRow(['d']);
        $line = $table->measure($this->doc, 160.0) / 4.0;
        $page = $this->doc->addPage(PageSize::A4);
        $page->fitTable($table, new Box(0.0, 0.0, 160.0, $line * 2.5));

        self::assertSame(
            1,
            $table->lastFit()?->rowsPlaced,
            'The spanned rows do not fit, so only the first row is placed.',
        );
    }

    #[Test]
    public function spansCoverColumnsAndRows(): void
    {
        $table = new Table(
            [Column::pt(50.0), Column::pt(50.0), Column::pt(50.0)],
            new CellStyle(border: new Border(1.0)),
            $this->body,
        );
        $tall = new Cell('tall');
        $table->addRow([$tall->spanning(1, 2), 'b', 'c']);
        $table->addRow(['d', 'e']);
        $table->addRow([Cell::text('wide')->spanning(3)]);
        $page = $this->doc->addPage(PageSize::A4);
        self::assertSame(FlowResult::Done, $page->fitTable($table, new Box(0.0, 0.0, 150.0, 500.0)));
        $info = $table->lastFit();
        self::assertNotNull($info);
        self::assertSame(3, $info->rowsPlaced);
        self::assertCount(4, $info->rowEdges);
        $this->doc->close();
        $pdf = $this->out->contents();
        // Only the "wide" cell's borders run the full 150 pt: its top and bottom edges.
        self::assertSame(2, preg_match_all('/^0 ([\d.]+) m\n150 \1 l$/m', $pdf));
    }

    #[Test]
    public function stylesLayerTableColumnRowAndCell(): void
    {
        $table = new Table(
            [Column::pt(80.0), Column::pt(80.0, new CellStyle(alignment: Alignment::Right))],
            new CellStyle(
                background: new Gray(0.9),
                padding: Padding::all(2.0),
            ),
            $this->body,
        );
        $table->addRow(new Row(['left', 'right'], new CellStyle(background: Gray::white())));
        $table->addRow([
            'plain',
            Cell::text('cell', new TextStyle(size: 20.0), new CellStyle(background: Gray::black())),
        ]);
        $page = $this->doc->addPage(PageSize::A4);
        $page->fitTable($table, new Box(0.0, 0.0, 160.0, 200.0));
        $this->doc->close();
        $pdf = $this->out->contents();
        self::assertStringContainsString('1 g', $pdf);
        self::assertStringContainsString('0.9 g', $pdf);
        self::assertSame(
            4,
            substr_count($pdf, needle: " re\nf\n"),
            'Every cell fills its background; black is the initial fill colour.',
        );
        self::assertStringContainsString(' 20 Tf', $pdf);
    }

    protected function setUp(): void
    {
        $this->out = new MemoryOutput();
        $this->doc = new Document($this->out, new DocumentOptions(
            compress: false,
            clock: new FixedClock('2026-01-01T00:00:00Z'),
        ));
        $font       = $this->doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $this->body = new TextStyle(
            font: $font,
            size: 10.0,
            leading: Leading::pt(12.0),
        );
    }

    private function bodyFont(): Font
    {
        $font = $this->body->font;
        self::assertInstanceOf(Font::class, $font);

        return $font;
    }

    private function firstBaselineOffset(FirstBaseline $mode): float
    {
        $font = $this->bodyFont();

        return match ($mode) {
            FirstBaseline::Ascender  => $font->ascender(10.0),
            FirstBaseline::CapHeight => $font->capHeight(10.0),
            FirstBaseline::XHeight   => $font->xHeight(10.0),
            FirstBaseline::Leading   => 12.0,
        };
    }
}
