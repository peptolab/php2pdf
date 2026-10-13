<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Table;

use Override;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Table\Cell;
use Php2Pdf\Table\CellStyle;
use Php2Pdf\Table\Column;
use Php2Pdf\Table\Table;
use Php2Pdf\Text\Style\TextStyle;
use Php2PdfTest\TestAsset\PdfTools;
use Php2PdfTest\TestAsset\TaggedDocument;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function preg_match_all;

/** A table's rows and cells tagged on a tagged page (#35). */
#[Group('integration')]
final class TableTaggingTest extends TestCase
{
    private Document $doc;
    private MemoryOutput $out;

    #[Test]
    public function aCellsArtworkIsItsContent(): void
    {
        $art = $this->doc->template(new Dimensions(10.0, 10.0));
        $art->canvas()->fillRect(0.0, 0.0, 10.0, 10.0);
        $table = $this->table(0)->addRow([Cell::of($art), Cell::text('Text'), Cell::empty()]);
        $pdf   = $this->drawn($table, 200.0);

        self::assertSame(
            [3, 1],
            [
                TaggedDocument::count($pdf, 'TD'),
                preg_match('#/S/TD/P \d+ 0 R/Pg \d+ 0 R/K\[\d+\]>>#', $pdf),
            ],
        );
    }

    #[Test]
    public function aRepeatedHeaderIsAnArtifact(): void
    {
        $pdf = $this->drawn($this->table(20), 100.0);

        self::assertSame(
            [3, 3],
            [
                TaggedDocument::count($pdf, 'TH'),
                preg_match_all('#/S/TH/P \d+ 0 R/K\[\d+ 0 R\]/A<</O/Table/Scope/Column>>#', $pdf),
            ],
        );
    }

    #[Test]
    public function aResetTableIsTaggedAfresh(): void
    {
        $table = $this->table(1);
        $page  = $this->doc->addPage();
        $page->fitTable($table, new Box(50.0, 500.0, 300.0, 200.0));
        $table->reset();
        $page->fitTable($table, new Box(50.0, 100.0, 300.0, 200.0));
        $this->doc->close();

        self::assertSame(2, TaggedDocument::count($this->out->contents(), 'Table'));
    }

    #[Test]
    public function aRowBrokenAcrossBoxesStaysOneRow(): void
    {
        $table = $this->table(0)->addRow([
            Cell::text('A cell with text long enough to wrap over many lines in its narrow column'),
            Cell::text('B'),
            Cell::text('C'),
        ]);
        $pdf = $this->drawn($table, 45.0);

        self::assertSame([2, 6], [TaggedDocument::count($pdf, 'TR'), TaggedDocument::count($pdf, 'T[HD]')]);
    }

    #[Test]
    public function rowsAndCellsAreTaggedInATable(): void
    {
        $pdf = $this->drawn($this->table(2), 300.0);

        self::assertSame(
            [1, 3, 3, 6],
            [
                TaggedDocument::count($pdf, 'Table'),
                TaggedDocument::count($pdf, 'TR'),
                TaggedDocument::count($pdf, 'TH'),
                TaggedDocument::count($pdf, 'TD'),
            ],
        );
    }

    #[Test]
    public function spanningCellsSayHowFarTheySpan(): void
    {
        $table = $this->table(0)
            ->addRow([Cell::text('Wide')->spanning(2), Cell::text('Tall')->spanning(1, 2)])
            ->addRow([Cell::text('A'), Cell::text('B')]);
        $pdf = $this->drawn($table, 300.0);

        self::assertSame(
            [1, 1],
            [
                preg_match('#/S/TD/P \d+ 0 R/K\[\d+ 0 R\]/A<</O/Table/ColSpan 2>>#', $pdf),
                preg_match('#/S/TD/P \d+ 0 R/K\[\d+ 0 R\]/A<</O/Table/RowSpan 2>>#', $pdf),
            ],
        );
    }

    #[Test]
    public function theFooterIsTaggedOnceTheBodyEnds(): void
    {
        $table = $this->table(20)->addFooterRow([Cell::text('Total')->spanning(3)]);
        $pdf   = $this->drawn($table, 100.0);

        self::assertSame(
            [22, 1],
            [
                TaggedDocument::count($pdf, 'TR'),
                preg_match('#/S/TR/P (\d+) 0 R/K\[\d+ 0 R\]>>(?:(?!/S/TR/).)*$#s', $pdf),
            ],
        );
    }

    #[Override]
    protected function setUp(): void
    {
        [$this->doc, $this->out] = TaggedDocument::open();
        $font = $this->doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $this->doc->styles()->define('body', new TextStyle(
            font: $font,
            size: 10.0,
        ));
    }

    /** Fits the table into boxes of the height on pages of their own until it is done; the output. */
    private function drawn(Table $table, float $height): string
    {
        do {
            $result = $this->doc->addPage()->fitTable($table, new Box(50.0, 100.0, 300.0, $height));
        } while (FlowResult::Done !== $result);
        $this->doc->close();

        return $this->out->contents();
    }

    /** A table of three columns, with a header row and the body rows asked for. */
    private function table(int $rows): Table
    {
        $table = $this->doc->table([Column::pt(60.0), Column::pt(60.0), Column::pt(60.0)], new CellStyle(), 'body');
        $table->addHeaderRow([Cell::text('Name'), Cell::text('Value'), Cell::text('Note')]);
        for ($row = 0; $row < $rows; ++$row) {
            $table->addRow([Cell::text("Row {$row}"), Cell::text((string) $row), Cell::text('-')]);
        }

        return $table;
    }
}
