<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Table;

use Override;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Table\CellStyle;
use Php2Pdf\Table\Column;
use Php2Pdf\Table\Grid;
use Php2Pdf\Table\Row;
use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\TextStyle;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** A table's cells as the grid places them: built when a row is first asked for, styled in layers. */
#[Group('integration')]
final class GridTest extends TestCase
{
    private Document $doc;
    private TextStyle $body;

    #[Test]
    public function aRowTheGridDoesNotHaveHasNoCells(): void
    {
        $grid = $this->grid([new Row(['only'])]);

        self::assertSame([], $grid->cells(1));
    }

    #[Test]
    public function aStyleLayerWithTextSetsTheTextOfItsCells(): void
    {
        $grid = $this->grid([new Row(['large'], new CellStyle(text: new TextStyle(size: 20.0)))]);

        self::assertSame(20.0, ($grid->cells(0)[0] ?? null)?->textStyle->size);
    }

    #[Test]
    public function cellsAlignedAlikeShareOneTextStyle(): void
    {
        $grid = $this->grid([new Row(['first']), new Row(['second'])], new CellStyle(alignment: Alignment::Right));

        $first  = ($grid->cells(0)[0] ?? null)?->textStyle;
        $second = ($grid->cells(1)[0] ?? null)?->textStyle;

        self::assertSame([Alignment::Right, $first], [$first?->alignment, $second]);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->doc  = new Document(new MemoryOutput());
        $this->body = new TextStyle(
            font: $this->doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf')),
            size: 10.0,
        );
    }

    /** @param list<Row> $rows */
    private function grid(array $rows, ?CellStyle $tableStyle = null): Grid
    {
        return new Grid($rows, [Column::pt(100.0)], $tableStyle, $this->body, $this->doc->text());
    }
}
