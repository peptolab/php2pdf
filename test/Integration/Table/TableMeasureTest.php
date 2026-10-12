<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Table;

use Override;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Table\Cell;
use Php2Pdf\Table\Column;
use Php2Pdf\Table\Table;
use Php2Pdf\Text\Style\Leading;
use Php2Pdf\Text\Style\TextStyle;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sprintf;
use function str_repeat;

use const INF;

#[Group('integration')]
final class TableMeasureTest extends TestCase
{
    private Document $doc;
    private TextStyle $body;

    #[Test]
    public function withoutALimitItMeasuresEveryRowAsMeasureDoes(): void
    {
        $table    = $this->table(40);
        $measured = $table->measureUpTo(200.0, INF);

        self::assertTrue($measured->complete);
        self::assertTrue($measured->fits());
        self::assertSame(40, $measured->rows);
        self::assertEqualsWithDelta($table->measure(200.0), $measured->height, 1.0e-6);
    }

    #[Test]
    public function itStopsSoonAfterPassingTheLimit(): void
    {
        $table    = $this->table(1000);
        $measured = $table->measureUpTo(200.0, 100.0);

        self::assertFalse($measured->complete);
        self::assertFalse($measured->fits());
        self::assertGreaterThan(100.0, $measured->height);
        self::assertLessThan(20, $measured->rows);
    }

    #[Test]
    public function aLimitEqualToTheHeightFitsAndOneJustBelowDoesNot(): void
    {
        $table  = $this->table(10);
        $height = $table->measure(200.0);

        self::assertTrue($table->measureUpTo(200.0, $height)->fits());
        self::assertFalse($table->measureUpTo(200.0, $height - 0.5)->fits());
    }

    #[Test]
    public function rowsJoinedByARowspanAreMeasuredTogether(): void
    {
        $table = $this->doc->table([Column::pt(80.0), Column::pt(80.0)], null, $this->body);
        $table->addRow([Cell::text(str_repeat('tall spanning text ', times: 8))->spanning(1, 3), 'a']);
        $table->addRow(['b']);
        $table->addRow(['c']);
        $table->addRow(['d', 'e']);
        $whole = $table->measure(160.0);

        $first = $table->measureUpTo(160.0, 1.0);

        self::assertSame(3, $first->rows);
        self::assertFalse($first->complete);
        self::assertLessThan($whole, $first->height);
        self::assertEqualsWithDelta($whole, $table->measureUpTo(160.0, $whole)->height, 1.0e-6);
    }

    #[Test]
    public function afterAFitItMeasuresOnlyTheRowsLeft(): void
    {
        $table = $this->table(100);
        $this->doc->addPage(PageSize::A4)->fitTable($table, new Box(0.0, 0.0, 200.0, 300.0));
        $placed = $table->lastFit()->rowsPlaced ?? 0;

        $measured = $table->measureUpTo(200.0, INF);

        self::assertSame(100 - $placed, $measured->rows);
        self::assertEqualsWithDelta($table->measure(200.0), $measured->height, 1.0e-6);
    }

    #[Test]
    public function aTableWithNoRowsLeftFitsAtTheHeightOfItsHeaders(): void
    {
        $table = $this->doc->table([Column::pt(200.0)], null, $this->body);
        $table->addHeaderRow(['Heading']);

        $measured = $table->measureUpTo(200.0, 100.0);

        self::assertTrue($measured->fits());
        self::assertSame(0, $measured->rows);
        self::assertEqualsWithDelta($table->measure(200.0), $measured->height, 1.0e-6);
    }

    #[Override]
    protected function setUp(): void
    {
        $this->doc  = new Document(new MemoryOutput(), new DocumentOptions(compress: false, clock: new FixedClock('2026-01-01T00:00:00Z')));
        $font       = $this->doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $this->body = new TextStyle(font: $font, size: 10.0, leading: Leading::pt(12.0));
    }

    private function table(int $rows): Table
    {
        $table = $this->doc->table([Column::pt(60.0), Column::pt(140.0)], null, $this->body);
        $table->addHeaderRow(['Ref', 'Description']);
        for ($i = 1; $i <= $rows; ++$i) {
            $table->addRow([sprintf('R%04d', $i), str_repeat('word ', times: 3 + $i % 9)]);
        }

        return $table;
    }
}
