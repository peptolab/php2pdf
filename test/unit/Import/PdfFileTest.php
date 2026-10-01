<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Import;

use Php2Pdf\Exception\PdfException;
use Php2Pdf\Import\PageBox;
use Php2Pdf\Import\PdfFile;
use Php2PdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class PdfFileTest extends TestCase
{
    /** @return iterable<string, array{0: string}> */
    public static function corpus(): iterable
    {
        yield 'classic xref table' => ['classic.pdf'];
        yield 'xref stream and object streams' => ['objstm.pdf'];
        yield 'linearized' => ['linearized.pdf'];
        yield 'incremental update' => ['incremental.pdf'];
        yield 'broken startxref (repair)' => ['broken-xref.pdf'];
    }

    #[Test]
    public function readsFilesFromOtherProducers(): void
    {
        $magick = PdfFile::open(PdfTools::assetPath('pdf/imagemagick.pdf'));
        self::assertSame(1, $magick->pageCount());
        self::assertStringContainsString('Do', $magick->page(1)->content());

        $cairo = PdfFile::open(PdfTools::assetPath('pdf/cairo.pdf'));
        self::assertSame(2, $cairo->pageCount());
        // cairo bakes the landscape orientation into the page size instead of using /Rotate.
        self::assertGreaterThan($cairo->page(2)->mediaBox()->height, $cairo->page(2)->mediaBox()->width);
    }

    #[Test]
    public function readsIncrementalUpdatesNewestFirst(): void
    {
        self::assertSame(
            'Import corpus: incrementally updated',
            PdfFile::open(PdfTools::assetPath('pdf/incremental.pdf'))->info()['Title'] ?? null,
        );
        self::assertSame(
            'Import corpus: classic xref',
            PdfFile::open(PdfTools::assetPath('pdf/classic.pdf'))->info()['Title'] ?? null,
        );
    }

    #[Test]
    #[DataProvider('corpus')]
    public function readsTheSameDocumentWhateverTheFileStructure(string $file): void
    {
        $pdf = PdfFile::open(PdfTools::assetPath("pdf/{$file}"));

        self::assertSame(2, $pdf->pageCount());
        $page = $pdf->page(1);
        self::assertEqualsWithDelta([0.0, 0.0, 419.53, 595.28], $page->mediaBox()->toRect(), 0.01);
        self::assertEqualsWithDelta([5.67, 5.67, 413.86, 589.61], $page->cropBox()->toRect(), 0.01);
        self::assertEqualsWithDelta([14.17, 14.17, 405.35, 581.1], $page->trimBox()->toRect(), 0.01);
        self::assertSame(0, $page->rotation());
        self::assertSame(90, $pdf->page(2)->rotation());
        self::assertStringContainsString('/X1 Do', $page->content());
        self::assertStringContainsString('BT /F1 16 Tf', $page->content());
        self::assertSame('PhpPdf', $pdf->info()['Author'] ?? null);
    }

    #[Test]
    public function rejectsEncryptedFiles(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Encrypted');
        PdfFile::open(PdfTools::assetPath('pdf/encrypted.pdf'));
    }

    #[Test]
    public function rejectsNonPdfData(): void
    {
        $this->expectException(PdfException::class);
        PdfFile::fromData('not a pdf at all');
    }

    #[Test]
    public function reportsMissingPages(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Page 3 does not exist');
        PdfFile::open(PdfTools::assetPath('pdf/classic.pdf'))->page(3);
    }

    #[Test]
    public function resolvesInheritedAttributesAndMixedFilters(): void
    {
        $pdf = PdfFile::open(PdfTools::assetPath('pdf/inherited.pdf'));

        self::assertSame(2, $pdf->pageCount());
        $first = $pdf->page(1);
        self::assertSame([0.0, 0.0, 200.0, 140.0], $first->mediaBox()->toRect());
        self::assertSame([10.0, 10.0, 190.0, 130.0], $first->cropBox()->toRect(), 'CropBox inherited from the parent.');
        self::assertSame([20.0, 20.0, 180.0, 120.0], $first->trimBox()->toRect());
        self::assertSame([20.0, 20.0, 180.0, 120.0], $first->box(PageBox::Trim)->toRect());
        self::assertSame(0, $first->rotation(), 'The page overrides the inherited rotation.');
        self::assertSame('0 0 1 rg 20 20 160 100 re f 1 0 0 RG 4 w 10 10 m 190 130 l S', $first->content());

        $second = $pdf->page(2);
        self::assertSame(90, $second->rotation(), 'Rotation inherited from the parent.');
        self::assertSame(
            [10.0, 10.0, 190.0, 130.0],
            $second->bleedBox()->toRect(),
            'BleedBox defaults to the CropBox.',
        );
        self::assertSame(
            "q 0.5 g 0 0 100 50 re f Q\nq 1 0 0 rg 100 50 100 50 re f Q",
            $second->content(),
            'Content arrays are joined; a wrong /Length is recovered.',
        );
        self::assertSame('Hand-made inheritance', $pdf->info()['Title'] ?? null);
    }
}
