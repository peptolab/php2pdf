<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Table;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\Page;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Font\FontInterface;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Table\CellStyle;
use Php2Pdf\Table\Column;
use Php2Pdf\Table\FooterContext;
use Php2Pdf\Table\Padding;
use Php2Pdf\Table\Table;
use Php2Pdf\Text\Style\Leading;
use Php2Pdf\Text\Style\TextStyle;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

/** Footer rows repeat after the last row of every box, filled per box when asked (#23). */
#[Group('integration')]
final class TableFooterTest extends TestCase
{
    private Document $doc;
    private Page $page;
    private TextStyle $body;

    #[Test]
    public function aBoxTooShortForHeaderAndFooterIsEmpty(): void
    {
        $table = $this->table(2);

        self::assertSame(
            FlowResult::BoxEmpty,
            $this->page->fitTable($table, new Box(0.0, 0.0, 200.0, 1.5 * $this->rowHeight())),
        );
    }

    #[Test]
    public function measureIncludesTheFooter(): void
    {
        self::assertEqualsWithDelta(5.0 * $this->rowHeight(), $this->table(3)->measure(200.0), 1.0e-6);
    }

    #[Test]
    public function theCallbackIsToldWhichRowsEachBoxHolds(): void
    {
        $contexts = [];
        $table    = $this->table(10)->onFooter(static function (FooterContext $context) use (&$contexts): ?array {
            $contexts[] = [$context->firstRow, $context->lastRow, $context->isLastBox];

            return null;
        });
        $this->fitAll($table);

        self::assertSame([[0, 3, false], [4, 7, false], [8, 9, true]], $contexts);
    }

    #[Test]
    public function theCallbackMustReturnOneRowPerFooterRow(): void
    {
        $table = $this->table(2)->onFooter(static fn(FooterContext $context): array => [['a', 'b'], ['c', 'd']]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('The footer callback returned 2 rows for a table with 1 footer rows.');
        $this->page->fitTable($table, new Box(0.0, 0.0, 200.0, 500.0));
    }

    #[Test]
    public function theFooterFollowsTheLastRowOfEachBox(): void
    {
        $table = $this->table(2);
        $this->page->fitTable($table, new Box(0.0, 0.0, 200.0, 500.0));
        $fit = $table->lastFit();
        self::assertNotNull($fit);

        self::assertSame([5, 4.0 * $this->rowHeight()], [count($fit->rowEdges), $fit->height]);
    }

    #[Test]
    public function theFooterHeightIsReservedInEveryBox(): void
    {
        self::assertSame([4, 4, 4, 4, 4], $this->fitAll($this->table(20)));
    }

    protected function setUp(): void
    {
        $this->doc  = new Document(new MemoryOutput());
        $this->page = $this->doc->addPage(PageSize::A4);
        $this->body = new TextStyle(
            font: $this->doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf')),
            size: 10.0,
            leading: Leading::pt(12.0),
        );
    }

    /** @return list<int|null> body rows placed in each 100 pt box until the table is done */
    private function fitAll(Table $table): array
    {
        $fits = [];
        do {
            $result = $this->page->fitTable($table, new Box(0.0, 0.0, 200.0, 100.0));
            $fits[] = $table->lastFit()?->rowsPlaced;
        } while (FlowResult::Done !== $result && count($fits) < 20);

        return $fits;
    }

    private function rowHeight(): float
    {
        $font = $this->body->font;
        self::assertInstanceOf(FontInterface::class, $font);

        return $font->ascender(10.0) - $font->descender(10.0) + 4.0;
    }

    private function table(int $rows): Table
    {
        $table = $this->doc->table(
            [Column::auto(), Column::pt(60.0)],
            new CellStyle(padding: Padding::symmetric(2.0, 0.0)),
            $this->body,
        );
        $table->addHeaderRow(['Item', 'Price']);
        for ($i = 1; $i <= $rows; ++$i) {
            $table->addRow(["Row {$i}", '1.00']);
        }

        return $table->addFooterRow(['Carried forward', '0.00']);
    }
}
