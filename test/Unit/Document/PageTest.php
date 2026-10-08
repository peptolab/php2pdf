<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Document;

use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\Page;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Document\PdfX;
use Php2Pdf\Exception\ConformanceException;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Exception\StateException;
use Php2Pdf\Flow\FlowableInterface;
use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Geometry\Box;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class PageTest extends TestCase
{
    /** @return array<string, array{0: int, 1: int}> */
    public static function rotationProvider(): array
    {
        return [
            'quarter turn'          => [90, 90],
            'negative quarter turn' => [-90, 270],
            'more than a full turn' => [450, 90],
        ];
    }

    private static function page(): Page
    {
        return (new Document(new MemoryOutput()))->addPage(PageSize::A4);
    }

    private static function pdfxPage(): Page
    {
        return (new Document(new MemoryOutput(), new DocumentOptions(pdfx: PdfX::X4)))->addPage(PageSize::A4);
    }

    #[Test]
    public function aBleedBoxDefaultsToTheCropBox(): void
    {
        $page = self::page()->setCropBox(new Box(10.0, 10.0, 200.0, 300.0));

        self::assertEquals($page->cropBox(), $page->bleedBox());
    }

    #[Test]
    public function anArtBoxDefaultsToTheCropBox(): void
    {
        $page = self::page()->setCropBox(new Box(10.0, 10.0, 200.0, 300.0));

        self::assertEquals($page->cropBox(), $page->artBox());
    }

    #[Test]
    public function aRefusedBleedBoxLeavesThePreviousOneInPlace(): void
    {
        $bleed = new Box(10.0, 10.0, 300.0, 400.0);
        $page  = self::pdfxPage()
            ->setBleedBox($bleed)
            ->setTrimBox(new Box(20.0, 20.0, 100.0, 100.0));

        try {
            $page->setBleedBox(new Box(50.0, 50.0, 200.0, 200.0));
            self::fail('The bleed box should not enclose the trim box.');
        } catch (ConformanceException) {
            self::assertEquals($bleed, $page->bleedBox());
        }
    }

    #[Test]
    public function aRefusedCropBoxLeavesThePreviousOneInPlace(): void
    {
        $crop = new Box(10.0, 10.0, 300.0, 400.0);
        $page = self::pdfxPage()
            ->setCropBox($crop)
            ->setTrimBox(new Box(20.0, 20.0, 100.0, 100.0));

        try {
            $page->setCropBox(new Box(50.0, 50.0, 200.0, 200.0));
            self::fail('The crop box should not enclose the trim box.');
        } catch (ConformanceException) {
            self::assertEquals($crop, $page->cropBox());
        }
    }

    #[Test]
    public function belongsToTheDocumentThatAddedIt(): void
    {
        $doc = new Document(new MemoryOutput());

        self::assertSame($doc, $doc->addPage()->document());
    }

    #[Test]
    public function clearingABoxRestoresItsDefault(): void
    {
        $page = self::page()
            ->setTrimBox(new Box(10.0, 10.0, 100.0, 100.0))
            ->setTrimBox(null);

        self::assertEquals($page->mediaBox(), $page->trimBox());
    }

    #[Test]
    public function flowReportsWhatTheContentReturns(): void
    {
        $content = $this->createStub(FlowableInterface::class);
        $content->method('fitInto')->willReturn(FlowResult::BoxFull);

        self::assertSame(FlowResult::BoxFull, self::page()->flow($content, new Box(0.0, 0.0, 100.0, 100.0)));
    }

    #[Test]
    public function isNumberedFromOne(): void
    {
        $doc = new Document(new MemoryOutput());
        $doc->addPage();

        self::assertSame(2, $doc->addPage()->number());
    }

    #[Test]
    #[DataProvider('rotationProvider')]
    public function normalisesTheRotationToAQuarterTurn(int $degrees, int $expected): void
    {
        self::assertSame($expected, self::page()->setRotation($degrees)->rotation());
    }

    #[Test]
    public function refusesABoxOutsideTheMediaBox(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Page box lies outside the media box.');
        self::page()->setTrimBox(new Box(1000.0, 1000.0, 10.0, 10.0));
    }

    #[Test]
    public function refusesARotationThatIsNotAQuarterTurn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Page rotation must be a multiple of 90 degrees.');
        self::page()->setRotation(45);
    }

    #[Test]
    public function refusesToBeFinishedTwice(): void
    {
        $doc  = new Document(new MemoryOutput());
        $page = $doc->addPage();
        $doc->close();

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('Page 1 has already been finished.');
        $page->finish(new Reference(id: 1));
    }

    #[Test]
    public function reportsATopDownCoordinateMode(): void
    {
        $page = (new Document(new MemoryOutput()))->addPage(PageSize::A4, topDown: true);

        self::assertTrue($page->isTopDown());
    }

    #[Test]
    public function settingTheArtBoxReturnsThePage(): void
    {
        $page = self::page();

        self::assertSame($page, $page->setArtBox(new Box(10.0, 10.0, 100.0, 100.0)));
    }

    #[Test]
    public function sharesItsResourcesWithItsCanvas(): void
    {
        $page = self::page();

        self::assertSame($page->canvas()->resources(), $page->resources());
    }
}
