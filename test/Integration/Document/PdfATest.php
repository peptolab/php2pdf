<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Document;

use Php2Pdf\Color\IccProfile;
use Php2Pdf\Color\Rgb;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\AttachmentRelationship;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\FacturXProfile;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Document\PdfA;
use Php2Pdf\Document\PdfX;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Import\ImportedAnnotations;
use Php2Pdf\Import\PdfFile;
use Php2PdfTest\TestAsset\Import\AnnotatedPdf;
use Php2PdfTest\TestAsset\LayeredPdf;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Documents written to PDF/A (#34), validated by veraPDF: text in TrueType and CFF fonts, a
 * link, transparency, an image, imported pages with layers and annotations, PDF/X-4 alongside,
 * and PDF/A-3 attachments with a Factur-X invoice.
 */
#[Group('integration')]
#[Group('pdfa')]
final class PdfATest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> the case, and the flavour veraPDF checks */
    public static function documentProvider(): array
    {
        return [
            'text, a link, transparency, an image and an imported page' => ['content', '2b'],
            'a page imported with its layers'                           => ['layers', '2b'],
            'a page imported with its annotations flattened'            => ['annotations', '2b'],
            'without object streams'                                    => ['plain', '2b'],
            'with PDF/X-4'                                              => ['pdfx', '2b'],
            'PDF/A-3 with a Factur-X invoice and another attachment'    => ['facturx', '3b'],
        ];
    }

    private static function options(string $case): DocumentOptions
    {
        $clock = new FixedClock('2026-01-01T12:00:00Z');

        return match ($case) {
            'plain' => new DocumentOptions(
                clock: $clock,
                objectStreams: false,
                pdfa: PdfA::A2b,
            ),
            'pdfx' => new DocumentOptions(
                clock: $clock,
                pdfx: PdfX::X4,
                pdfa: PdfA::A2b,
            ),
            'facturx' => new DocumentOptions(
                clock: $clock,
                pdfa: PdfA::A3b,
            ),
            default   => new DocumentOptions(
                clock: $clock,
                pdfa: PdfA::A2b,
            ),
        };
    }

    #[Test]
    #[DataProvider('documentProvider')]
    public function aDocumentWrittenToPdfAPassesVeraPdf(string $case, string $flavour): void
    {
        $path = PdfTools::outputDir() . "/pdfa-{$case}.pdf";
        $doc  = new Document($path, self::options($case));
        $doc->setOutputIntent(IccProfile::fromFile(PdfTools::assetPath('icc/sRGB-v2-micro.icc')), 'sRGB IEC61966-2.1');
        $doc->info()->setTitle("PDF/A: {$case}");
        $page = $doc->addPage(PageSize::A4);
        $page->setTrimBox(new Box(10.0, 10.0, 575.0, 822.0));
        $canvas = $page->canvas();
        $canvas->drawText(
            'Archived text',
            $doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf')),
            14.0,
            50.0,
            780.0,
        );
        $canvas->drawText(
            'in two fonts',
            $doc->fonts()->load(PdfTools::assetPath('fonts/SourceSans3-Regular.otf')),
            14.0,
            50.0,
            760.0,
        );
        match ($case) {
            'content'     => $this->content($doc),
            'layers' => $doc->addPage(PageSize::A4)->place(
                $doc->import(PdfFile::fromData(LayeredPdf::make(
                    content: '/OC /Art BDC 1 0 0 rg 0 0 50 50 re f EMC /OC /Guides BDC 0 g 50 50 50 50 re f EMC',
                )))->page(1),
                new Box(50.0, 400.0, 300.0, 300.0),
                Fit::meet(),
            ),
            'annotations' => $doc->addPage(PageSize::A4)->place(
                $doc->import(PdfFile::fromData(AnnotatedPdf::pdf()), annotations: ImportedAnnotations::Flatten)->page(
                    1,
                ),
                new Box(50.0, 400.0, 300.0, 300.0),
                Fit::meet(),
            ),
            'facturx' => $doc->attachFacturX(
                '<?xml version="1.0" encoding="UTF-8"?><rsm:CrossIndustryInvoice'
                    . ' xmlns:rsm="urn:un:unece:uncefact:data:standard:CrossIndustryInvoice:100"/>',
                FacturXProfile::En16931,
            )
                ->attachFile('notes.txt', "Notes\n", 'text/plain', AttachmentRelationship::Supplement, 'Notes'),
            default       => null,
        };
        $doc->close();
        PdfTools::assertValid($path);

        self::assertSame([], PdfTools::veraPdf($path, $flavour));
    }

    /** A filled square, a half-transparent one over it, a link, a JPEG and an imported page. */
    private function content(Document $doc): void
    {
        $page   = $doc->currentPage() ?? $doc->addPage(PageSize::A4);
        $canvas = $page->canvas();
        $canvas->setFillColor(new Rgb(0.8, 0.1, 0.1))->fillRect(50.0, 650.0, 100.0, 50.0);
        $canvas->save()
            ->setOpacity(0.5)
            ->setFillColor(new Rgb(0.0, 0.0, 1.0))
            ->fillRect(100.0, 670.0, 100.0, 50.0)
            ->restore();
        $page->addLink(new Box(50.0, 600.0, 200.0, 20.0), 'https://example.com/');
        $page->place(
            $doc->image(PdfTools::assetPath('images/rgb.jpg')),
            new Box(50.0, 300.0, 200.0, 200.0),
            Fit::meet(),
        );
        $doc->addPage(PageSize::A4)->place(
            $doc->import(PdfTools::assetPath('pdf/classic.pdf'))->page(1),
            new Box(0.0, 0.0, 595.0, 842.0),
            Fit::meet(),
        );
    }
}
