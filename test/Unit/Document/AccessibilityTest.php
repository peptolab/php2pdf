<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Document;

use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Accessibility;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\Page;
use Php2Pdf\Document\PdfA;
use Php2Pdf\Document\PdfX;
use Php2Pdf\Exception\ConformanceException;
use Php2Pdf\Font\GlyphCheck;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Navigation\Destination;
use Php2Pdf\Structure\StructureRole;
use Php2PdfTest\TestAsset\StubIccProfile;
use Php2PdfTest\TestAsset\TaggedDocument;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function str_contains;

/** Tagged documents and PDF/UA-1 (#35): the catalog, the checks at close, pages, links and figures. */
#[Group('unit')]
final class AccessibilityTest extends TestCase
{
    /** @return array<string, array{0: DocumentOptions, 1: ?string}> */
    public static function standardsProvider(): array
    {
        $ua = new Accessibility('en', pdfUa: true);

        return [
            'none'                 => [new DocumentOptions(), null],
            'tagged only'          => [new DocumentOptions(accessibility: new Accessibility('en')), null],
            'PDF/UA'               => [new DocumentOptions(accessibility: $ua), 'PDF/UA-1'],
            'PDF/A and PDF/UA'     => [
                new DocumentOptions(
                    pdfa: PdfA::A2b,
                    accessibility: $ua,
                ),
                'PDF/A-2b and PDF/UA-1',
            ],
            'PDF/X without PDF/UA' => [new DocumentOptions(pdfx: PdfX::X4), 'PDF/X-4'],
        ];
    }

    /** The output of a document closed after the closure drew its one page. */
    private static function closed(callable $draw, ?Accessibility $accessibility = new Accessibility('en-GB')): string
    {
        [$doc, $out] = TaggedDocument::open($accessibility);
        $draw($doc->addPage());
        $doc->close();

        return $out->contents();
    }

    #[Test]
    public function aLinkIsTaggedAndDescribedByItsTooltipOrTarget(): void
    {
        $pdf = self::closed(static function (Page $page): void {
            $page->addLink(new Box(0.0, 0.0, 10.0, 10.0), 'https://example.com/');
            $page->addLink(new Box(0.0, 20.0, 10.0, 10.0), Destination::at(1, 0.0, 0.0));
            $page->addLink(new Box(0.0, 40.0, 10.0, 10.0), 'https://example.org/', 'Example');
        });

        self::assertSame(
            [3, 1, 1, 1, 1],
            [
                TaggedDocument::count($pdf, 'Link'),
                preg_match('#/Contents\(https://example\.com/\)/StructParent 1/A#', $pdf),
                preg_match('#/Contents\(A link to a place in this document\)/StructParent 2/Dest#', $pdf),
                preg_match('#/Contents\(Example\)/StructParent 3/A#', $pdf),
                preg_match('#/Annots\[\d+ 0 R \d+ 0 R \d+ 0 R\].*/StructParents 0/Tabs/S#', $pdf),
            ],
        );
    }

    #[Test]
    public function anUntaggedDocumentHasNoStructure(): void
    {
        $pdf = self::closed(static function (Page $page): void {
            $page->addLink(new Box(0.0, 0.0, 10.0, 10.0), 'https://example.com/');
            $page->place(
                $page->document()->template(new Dimensions(10.0, 10.0)),
                new Box(0.0, 0.0, 10.0, 10.0),
                alt: 'Art',
            );
        }, null);

        self::assertSame(
            [false, false, false, false],
            [
                str_contains($pdf, 'StructTreeRoot'),
                str_contains($pdf, 'StructParent'),
                str_contains($pdf, 'MarkInfo'),
                str_contains($pdf, 'BDC'),
            ],
        );
    }

    #[Test]
    #[DataProvider('standardsProvider')]
    public function pdfUaIsAmongTheStandardsTheDocumentConformsTo(DocumentOptions $options, ?string $standards): void
    {
        self::assertSame($standards, $options->standards());
    }

    #[Test]
    public function pdfUaIsDeclaredAndShowsTheTitle(): void
    {
        $pdf = self::closed(static function (): void {}, new Accessibility('en', pdfUa: true));

        self::assertSame(
            [1, 1, 0],
            [
                preg_match('#<pdfuaid:part>1</pdfuaid:part>#', $pdf),
                preg_match('#/Lang\(en\)/ViewerPreferences<</DisplayDocTitle true>>#', $pdf),
                preg_match('#pdfaSchema#', $pdf),
            ],
        );
    }

    #[Test]
    public function pdfUaRefusesADocumentWithoutALanguage(): void
    {
        [$doc] = TaggedDocument::open(new Accessibility(pdfUa: true));
        $doc->addPage();

        $this->expectException(ConformanceException::class);
        $this->expectExceptionMessage("PDF/UA-1 needs the document's language: set Accessibility::\$language.");
        $doc->close();
    }

    #[Test]
    public function pdfUaRefusesADocumentWithoutATitle(): void
    {
        [$doc] = TaggedDocument::open(new Accessibility('en', pdfUa: true));
        $doc->info()->setTitle(null);
        $doc->addPage();

        $this->expectException(ConformanceException::class);
        $this->expectExceptionMessage('PDF/UA-1 needs a document title.');
        $doc->close();
    }

    #[Test]
    public function pdfUaRefusesADocumentWithoutXmp(): void
    {
        $doc = new Document(
            new MemoryOutput(),
            new DocumentOptions(
                xmp: false,
                accessibility: new Accessibility('en', pdfUa: true),
            ),
        );
        $doc->info()->setTitle('Tagged');
        $doc->addPage();

        $this->expectException(ConformanceException::class);
        $this->expectExceptionMessage('PDF/UA-1 needs XMP metadata; leave DocumentOptions::$xmp enabled.');
        $doc->close();
    }

    #[Test]
    public function pdfUaWithPdfADeclaresItsSchema(): void
    {
        [$doc, $out] = TaggedDocument::open(new Accessibility('en', pdfUa: true), PdfA::A2b);
        $doc->setOutputIntent(StubIccProfile::of('RGB '), 'Test condition');
        $doc->addPage();
        $doc->close();

        self::assertSame(
            1,
            preg_match(
                '#<pdfaSchema:namespaceURI>http://www\.aiim\.org/pdfua/ns/id/</pdfaSchema:namespaceURI><pdfaSchema:prefix>pdfuaid</pdfaSchema:prefix>#',
                $out->contents(),
            ),
        );
    }

    #[Test]
    public function pdfUaWritesPdf17AndRefusesMissingGlyphs(): void
    {
        $options = new DocumentOptions(
            version: '1.4',
            accessibility: new Accessibility('en', pdfUa: true),
        );

        self::assertSame(['1.7', GlyphCheck::Error], [$options->version, $options->glyphCheck]);
    }

    #[Test]
    public function placedArtworkWithAlternateTextIsAFigure(): void
    {
        $pdf = self::closed(static function (Page $page): void {
            $art = $page->document()->template(new Dimensions(10.0, 10.0));
            $page->place($art, new Box(0.0, 0.0, 10.0, 10.0), alt: 'A logo');
            $page->place($art, new Box(20.0, 0.0, 10.0, 10.0));
        });

        self::assertSame(
            [1, ['/Figure 0', 'EMC', 'Artifact', 'EMC']],
            [preg_match('#/S/Figure/P \d+ 0 R/Pg \d+ 0 R/K\[0\]/Alt\(A logo\)#', $pdf), TaggedDocument::marks($pdf)],
        );
    }

    #[Test]
    public function placedArtworkWithoutAlternateTextIsPartOfTheElementBeingDrawn(): void
    {
        $pdf = self::closed(static function (Page $page): void {
            $art = $page->document()->template(new Dimensions(10.0, 10.0));
            $page->canvas()->tag(StructureRole::Paragraph, static function () use ($page, $art): void {
                $page->place($art, new Box(0.0, 0.0, 10.0, 10.0));
            });
        });

        self::assertSame(['/P 0', 'EMC'], TaggedDocument::marks($pdf));
    }

    #[Test]
    public function theCatalogSaysTheDocumentIsTaggedAndInWhichLanguage(): void
    {
        $pdf = self::closed(static function (Page $page): void {
            $page->canvas()->tag(StructureRole::Paragraph, static function (Canvas $canvas): void {
                $canvas->fillRect(0.0, 0.0, 10.0, 10.0);
            });
        });

        self::assertSame(
            [1, 0],
            [
                preg_match('#/StructTreeRoot \d+ 0 R/MarkInfo<</Marked true>>/Lang\(en-GB\)>>#', $pdf),
                preg_match('#ViewerPreferences|pdfuaid#', $pdf),
            ],
        );
    }

    #[Test]
    public function withoutALanguageATaggedDocumentHasNone(): void
    {
        $pdf = self::closed(static function (): void {}, new Accessibility());

        self::assertSame([1, 0], [preg_match('#/MarkInfo<</Marked true>>#', $pdf), preg_match('#/Lang#', $pdf)]);
    }
}
