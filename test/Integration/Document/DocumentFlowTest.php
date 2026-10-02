<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Document;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Dimensions;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\Page;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\FontInterface;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\TextFormat;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_contains;
use function substr_count;

/** Flowing through several frames per page, and callbacks when the document closes (#25). */
#[Group('integration')]
final class DocumentFlowTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;
    private TextStyle $style;

    /** @return array<string, array{0: string, 1: int}> */
    public static function framesProvider(): array
    {
        return [
            'columns fill in turn'           => ['A<columnbreak>B<columnbreak>C', 2],
            'a page break skips the columns' => ['A<pagebreak>B', 2],
            'one column of text'             => ['A', 1],
        ];
    }

    private static function column(int $index): Box
    {
        return new Box(40.0 + ($index * 250.0), 40.0, 200.0, 500.0);
    }

    #[Test]
    public function aCloseCallbackCanStillDrawOnTheLastPage(): void
    {
        $this->doc->addPage(PageSize::A5);
        $this->doc->onClose(static function (Document $doc): void {
            $doc->currentPage()?->canvas()->fillRect(1.0, 2.0, 3.0, 4.0);
        });
        $this->doc->close();

        self::assertTrue(str_contains($this->out->contents(), '1 2 3 4 re'));
    }

    #[Test]
    public function aFirstFrameTooSmallFails(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('The box is too small');
        $this->doc->flow($this->doc->textflow('A', $this->style), [[new Box(0.0, 0.0, 100.0, 1.0)]], $this->page(...));
    }

    #[Test]
    public function aFrameTooSmallAfterTheFirstIsSkipped(): void
    {
        $flow = $this->doc->textflow('A<columnbreak>B', $this->style, TextFormat::Markup);

        self::assertSame(
            1,
            $this->doc->flow(
                $flow,
                [[self::column(0), new Box(300.0, 300.0, 100.0, 1.0), self::column(1)]],
                $this->page(...),
            ),
        );
    }

    #[Test]
    public function aPageNeedsAFrame(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('flow() needs at least one frame on every page.');
        $this->doc->flow($this->doc->textflow('A', $this->style), [[]], $this->page(...));
    }

    #[Test]
    public function aTemplateOnEveryPageCanShowThePageTotal(): void
    {
        $total = $this->doc->template(new Dimensions(20.0, 10.0));
        $this->doc->onClose(function (Document $doc) use ($total): void {
            $total->canvas()->drawText((string) $doc->pageCount(), $this->font(), 8.0, 0.0, 2.0);
        });
        for ($i = 0; $i < 3; ++$i) {
            $this->doc->addPage(PageSize::A5)->place($total, new Box(10.0, 10.0, 20.0, 10.0));
        }
        $this->doc->close();
        $pdf = $this->out->contents();

        self::assertSame([3, true], [substr_count($pdf, needle: ' Do Q'), str_contains($pdf, 'BT /F1 8 Tf')]);
    }

    #[Test]
    public function closeCallbacksSeeTheFinalPageCount(): void
    {
        $counts = [];
        $this->doc->onClose(static function (Document $doc) use (&$counts): void {
            $counts[] = $doc->pageCount();
        });
        $this->doc->addPage(PageSize::A5);
        $this->doc->addPage(PageSize::A5);
        $this->doc->close();

        self::assertSame([2], $counts);
    }

    #[Test]
    #[DataProvider('framesProvider')]
    public function contentMovesFromFrameToFrameThenPage(string $markup, int $pages): void
    {
        $flow = $this->doc->textflow($markup, $this->style, TextFormat::Markup);

        self::assertSame($pages, $this->doc->flow($flow, [[self::column(0), self::column(1)]], $this->page(...)));
    }

    #[Test]
    public function eachPageUsesItsOwnFramesAndTheLastRepeats(): void
    {
        $flow = $this->doc->textflow('A<columnbreak>B<columnbreak>C<columnbreak>D', $this->style, TextFormat::Markup);

        self::assertSame(3, $this->doc->flow(
            $flow,
            [self::column(0), [self::column(0), self::column(1)]],
            $this->page(...),
        ));
    }

    protected function setUp(): void
    {
        $this->out   = new MemoryOutput();
        $this->doc   = new Document($this->out, new DocumentOptions(compress: false));
        $this->style = new TextStyle(
            font: $this->font(),
            size: 10.0,
        );
    }

    private function font(): FontInterface
    {
        return $this->doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
    }

    private function page(Document $doc): Page
    {
        return $doc->addPage(PageSize::A4);
    }
}
