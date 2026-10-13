<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Document;

use Php2Pdf\Color\Gray;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PdfA;
use Php2Pdf\Document\PdfX;
use Php2Pdf\Exception\ConformanceException;
use Php2Pdf\Font\GlyphCheck;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Import\PdfFile;
use Php2PdfTest\TestAsset\HostileFiles;
use Php2PdfTest\TestAsset\StubIccProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function substr_count;

#[Group('unit')]
final class ConformanceRulesTest extends TestCase
{
    /** @return array<string, array{0: ?PdfX, 1: ?PdfA, 2: string, 3: GlyphCheck}> the standards, version and glyph check they set */
    public static function optionsProvider(): array
    {
        return [
            'neither'       => [null, null, '1.7', GlyphCheck::Warn],
            'PDF/X-4'       => [PdfX::X4, null, '1.6', GlyphCheck::Error],
            'PDF/A-2b'      => [null, PdfA::A2b, '1.7', GlyphCheck::Error],
            'PDF/A-3b'      => [null, PdfA::A3b, '1.7', GlyphCheck::Error],
            'PDF/X-4 and A' => [PdfX::X4, PdfA::A2b, '1.6', GlyphCheck::Error],
        ];
    }

    /**
     * A PDF/A document, with PDF/X-4 too when asked, closed with its intent and a page, which has a
     * link unless PDF/X keeps links off it; its output.
     */
    private static function archived(?PdfX $pdfx = null, ?string $title = null): string
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(
            compress: false,
            clock: new FixedClock('2026-01-01T00:00:00Z'),
            pdfx: $pdfx,
            pdfa: PdfA::A2b,
        ));
        $doc->setOutputIntent(StubIccProfile::of('RGB '), 'Test condition');
        if (null !== $title) {
            $doc->info()->setTitle($title);
        }
        $page = $doc->addPage();
        if (null === $pdfx) {
            $page->addLink(new Box(0.0, 0.0, 10.0, 10.0), 'https://example.com/');
        }
        $doc->close();

        return $out->contents();
    }

    private static function document(string $space, bool $xmp): Document
    {
        $doc = new Document(
            new MemoryOutput(),
            new DocumentOptions(
                compress: false,
                clock: new FixedClock('2026-01-01T00:00:00Z'),
                xmp: $xmp,
                pdfx: PdfX::X4,
            ),
        );
        $doc->setOutputIntent(StubIccProfile::of($space), 'Test condition');
        $doc->info()->setTitle('PDF/X');
        $doc->addPage();

        return $doc;
    }

    #[Test]
    public function aDocumentWithoutPdfXAcceptsAnyImportedColour(): void
    {
        $this->expectNotToPerformAssertions();
        (new Document(new MemoryOutput()))->colour()
            ->assertImportedColour('DeviceRGB', page: 1);
    }

    #[Test]
    public function anImportedPageWithFontsNotEmbeddedIsRefused(): void
    {
        $doc = new Document(new MemoryOutput(), new DocumentOptions(pdfa: PdfA::A2b));
        $doc->setOutputIntent(StubIccProfile::of('RGB '), 'Test condition');
        $page = $doc->import(PdfFile::fromData(HostileFiles::pdf([
            '<< /Type /Catalog /Pages 2 0 R >>',
            '<< /Type /Pages /Kids [3 0 R] /Count 1 >>',
            '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 100 100] /Resources << /Font << /F1 4 0 R /F2 5 0 R >> >> >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica >>',
            '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>',
        ])))->page(1);

        $this->expectException(ConformanceException::class);
        $this->expectExceptionMessage(
            'PDF/A-2b requires every font to be embedded, and imported page 1 uses Helvetica, Courier without embedding them.',
        );
        $doc->addPage()->place($page, new Box(0.0, 0.0, 100.0, 100.0));
    }

    #[Test]
    public function aPdfADocumentDeclaresItsPartAndLevelAndNeedsNoTitle(): void
    {
        self::assertStringContainsString(
            '<pdfaid:part>2</pdfaid:part><pdfaid:conformance>B</pdfaid:conformance>',
            self::archived(),
        );
    }

    #[Test]
    public function errorsNameEveryStandardTheDocumentConformsTo(): void
    {
        $doc = new Document(
            new MemoryOutput(),
            new DocumentOptions(
                pdfx: PdfX::X4,
                pdfa: PdfA::A2b,
            ),
        );

        $this->expectException(ConformanceException::class);
        $this->expectExceptionMessage('PDF/X-4 and PDF/A-2b needs an output intent before any colour');
        $doc->addPage()
            ->canvas()
            ->setFillColor(new Gray(0.5));
    }

    #[Test]
    public function linksPrintUnderPdfA(): void
    {
        self::assertStringContainsString('/Border[0 0 0]/F 4', self::archived());
    }

    #[Test]
    public function pdfAAloneWritesItsOwnOutputIntent(): void
    {
        $pdf = self::archived();

        self::assertSame([1, 0], [
            substr_count($pdf, needle: '/S/GTS_PDFA1'),
            substr_count($pdf, needle: '/S/GTS_PDFX'),
        ]);
    }

    #[Test]
    public function pdfARefusesTheStandardFonts(): void
    {
        $doc = new Document(new MemoryOutput(), new DocumentOptions(pdfa: PdfA::A3b));

        $this->expectException(ConformanceException::class);
        $this->expectExceptionMessage('PDF/A-3b requires every font to be embedded');
        $doc->fonts()->find('Helvetica');
    }

    #[Test]
    public function pdfAWithPdfXWritesBothIntentsAndDeclaresPdfXsProperties(): void
    {
        $pdf = self::archived(PdfX::X4, 'Both');

        self::assertSame(
            [1, 1, 1, 1],
            [
                substr_count($pdf, needle: '/S/GTS_PDFA1'),
                substr_count($pdf, needle: '/S/GTS_PDFX'),
                substr_count($pdf, needle: '<pdfaSchema:prefix>pdfxid</pdfaSchema:prefix>'),
                substr_count($pdf, needle: '<pdfaSchema:prefix>pdf</pdfaSchema:prefix>'),
            ],
        );
    }

    #[Test]
    public function refusesToCloseWithoutXmp(): void
    {
        $doc = self::document('CMYK', xmp: false);

        $this->expectException(ConformanceException::class);
        $this->expectExceptionMessage('PDF/X-4 needs XMP metadata; leave DocumentOptions::$xmp enabled.');
        $doc->close();
    }

    #[Test]
    #[DataProvider('optionsProvider')]
    public function theStandardsSetTheVersionAndTheGlyphCheck(
        ?PdfX $pdfx,
        ?PdfA $pdfa,
        string $version,
        GlyphCheck $check,
    ): void {
        $options = new DocumentOptions(
            pdfx: $pdfx,
            pdfa: $pdfa,
        );

        self::assertSame([$version, $check], [$options->version, $options->glyphCheck]);
    }
}
