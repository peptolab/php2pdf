<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Document;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\Page;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Exception\StateException;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Table\Column;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function gzuncompress;
use function preg_match;

#[Group('unit')]
final class DocumentTest extends TestCase
{
    private static function build(): string
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->info()->setTitle('Test document')->setAuthor('Php2Pdf tests');

        $page = $doc->addPage(PageSize::A4);
        $page->setTrimBox(Box::fromMm(10, 10, 190, 277));
        $page->canvas()->fillRect(10, 10, 100, 100);

        $doc->addPage(PageSize::Letter->landscape())->setRotation(90);
        $doc->close();

        return $out->contents();
    }

    private static function streamData(string $pdf): string
    {
        $m = [];
        self::assertSame(1, preg_match('/stream\n(.*?)\nendstream/s', $pdf, $m));
        $data = $m[1] ?? null;
        self::assertNotNull($data);

        return $data;
    }

    #[Test]
    public function aClosedDocumentRefusesNewPages(): void
    {
        $doc = new Document(new MemoryOutput());
        $doc->addPage();
        $doc->close();

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('The document has been closed.');
        $doc->addPage();
    }

    #[Test]
    public function closingTwiceWritesNothingMore(): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out);
        $doc->addPage();
        $doc->close();
        $written = $out->contents();
        $doc->close();

        self::assertSame($written, $out->contents());
    }

    #[Test]
    public function encodesNonAsciiTextAsUtf16(): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(
            clock: new FixedClock('2026-01-01T12:00:00Z'),
            xmp: false,
        ));
        $doc->info()->setAuthor('Zoë');
        $doc->addPage();
        $doc->close();

        self::assertStringContainsString("/Author(\xFE\xFF\x00Z\x00o\x00\xEB)", $out->contents());
    }

    #[Test]
    public function exposesTheClockFromItsOptions(): void
    {
        $clock = new FixedClock('2026-01-01T12:00:00Z');

        self::assertSame($clock, (new Document(new MemoryOutput(), new DocumentOptions(clock: $clock)))->clock());
    }

    #[Test]
    public function flowNeedsAtLeastOneFrame(): void
    {
        $doc = new Document(new MemoryOutput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('flow() needs at least one box.');
        $doc->flow($doc->table([Column::auto()]), [], static fn(Document $d): Page => $d->addPage());
    }

    #[Test]
    public function outputIsByteIdenticalAcrossRuns(): void
    {
        self::assertSame(self::build(), self::build());
    }

    #[Test]
    public function refusesDrawingOnFinishedPage(): void
    {
        $doc  = new Document(new MemoryOutput());
        $page = $doc->addPage();
        $doc->addPage();

        $this->expectException(StateException::class);
        $page->canvas();
    }

    #[Test]
    public function refusesEmptyDocument(): void
    {
        $doc = new Document(new MemoryOutput());

        $this->expectException(StateException::class);
        $doc->close();
    }

    #[Test]
    public function topDownPagesFlipBoxes(): void
    {
        $out  = new MemoryOutput();
        $doc  = new Document($out, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $page = $doc->addPage(PageSize::A4, topDown: true);
        $page->setTrimBox(new Box(10, 20, 100, 50));
        self::assertSame([10.0, 20.0, 110.0, 70.0], $page->trimBox()->toRect());
        $doc->close();

        $pdf = $out->contents();
        self::assertStringContainsString('/TrimBox[10 771.8898 110 821.8898]', $pdf);
        $content = gzuncompress(self::streamData($pdf));
        self::assertIsString($content);
        self::assertStringContainsString('1 0 0 -1 0 841.8898 cm', $content);
    }

    #[Test]
    public function writesPagesBoxesAndMetadata(): void
    {
        $pdf = self::build();

        self::assertStringContainsString('/Type/Pages/Kids[', $pdf);
        self::assertStringContainsString('/Count 2', $pdf);
        self::assertStringContainsString('/MediaBox[0 0 595.2756 841.8898]', $pdf);
        self::assertStringContainsString('/TrimBox[28.3465 28.3465 566.9291 813.5433]', $pdf);
        self::assertStringContainsString('/Rotate 90', $pdf);
        self::assertStringContainsString('/Title(Test document)', $pdf);
        self::assertStringContainsString('/Producer(Php2Pdf)', $pdf);
        self::assertStringContainsString('/CreationDate(D:20260101120000Z)', $pdf);
        self::assertStringContainsString('<dc:title>', $pdf);
        self::assertStringContainsString('/Type/Metadata/Subtype/XML', $pdf);
    }
}
