<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Import;

use GdImage;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PdfX;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Fit\Position;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Import\HiddenLayers;
use Php2Pdf\Import\ImportedAnnotations;
use Php2Pdf\Import\ImportedPage;
use Php2Pdf\Import\PdfFile;
use Php2PdfTest\TestAsset\Import\AnnotatedPdf;
use Php2PdfTest\TestAsset\PdfTools;
use Php2PdfTest\TestAsset\StubIccProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function imagecolorat;
use function imagecreatefrompng;
use function preg_match;
use function preg_quote;
use function str_contains;

/**
 * Annotations of imported pages flattened (#38): AnnotatedPdf's page, whose squares are drawn
 * where they print and left out where they do not, and whose link to a web address comes back
 * where the page is placed.
 */
#[Group('integration')]
final class ImportedAnnotationsTest extends TestCase
{
    /** @return array<string, array{0: bool, 1: string}> whether the page is top-down, and the link's /Rect */
    public static function placementProvider(): array
    {
        return [
            'a page measured from the bottom' => [false, '[170 140 190 160]'],
            'a top-down page'                 => [true, '[170 240 190 260]'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function rendererProvider(): array
    {
        return ['poppler' => ['poppler'], 'Ghostscript' => ['ghostscript'], 'CoreGraphics' => ['coregraphics']];
    }

    /**
     * The colour at a pixel, by the nearest of the fixture's: red, magenta, yellow or white, which
     * a transparent pixel (where CoreGraphics leaves the page blank) counts as.
     */
    private static function colour(GdImage $png, int $x, int $y): string
    {
        $rgb = imagecolorat($png, $x, $y);
        if ((($rgb >> 24) & 0x7F) > 100) {
            return 'white';
        }
        $r = (($rgb >> 16) & 0xFF) > 128;
        $g = (($rgb >> 8) & 0xFF) > 128;
        $b = ($rgb & 0xFF) > 128;

        return match ([$r, $g, $b]) {
            [true, false, false] => 'red',
            [true, false, true] => 'magenta',
            [true, true, false] => 'yellow',
            [true, true, true] => 'white',
            default => 'other',
        };
    }

    private static function import(
        Document $doc,
        ImportedAnnotations $annotations = ImportedAnnotations::Flatten,
    ): ImportedPage {
        return $doc->import(PdfFile::fromData(AnnotatedPdf::pdf()), annotations: $annotations)->page(1);
    }

    #[Test]
    #[DataProvider('placementProvider')]
    public function aLinkComesBackWhereThePageIsPlaced(bool $topDown, string $rect): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(compress: false));
        $doc->addPage(new Dimensions(400.0, 400.0), $topDown)->place(
            self::import($doc),
            new Box(100.0, 100.0, 100.0, 100.0),
            Fit::entire(),
        );
        $doc->close();

        self::assertSame(1, preg_match(
            '#/Rect' . preg_quote($rect, delimiter: '#') . '/Border\[0 0 0\]/A<</S/URI/URI\(https://example\.com/\)#',
            $out->contents(),
        ));
    }

    #[Test]
    public function aLinkOutsideTheClipOfAPlacementIsCutToIt(): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(compress: false));
        $doc->addPage(new Dimensions(200.0, 200.0))->place(
            self::import($doc),
            new Box(0.0, 0.0, 160.0, 200.0),
            Fit::clip(Position::bottomLeft()),
        );
        $doc->close();

        self::assertStringContainsString('/Rect[140 80 160 120]', $out->contents());
    }

    #[Test]
    public function annotationsAreLeftOutUnlessAsked(): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(compress: false));
        $doc->addPage(new Dimensions(200.0, 200.0))->place(
            self::import($doc, ImportedAnnotations::None),
            new Box(0.0, 0.0, 200.0, 200.0),
            Fit::entire(),
        );
        $doc->close();

        self::assertSame([false, false], [
            str_contains($out->contents(), '/Annot0'),
            str_contains($out->contents(), '/URI'),
        ]);
    }

    #[Test]
    public function aTemplateTheImportedPageIsPlacedInHasNoLinks(): void
    {
        $out      = new MemoryOutput();
        $doc      = new Document($out, new DocumentOptions(compress: false));
        $template = $doc->template(new Dimensions(200.0, 200.0));
        $template->canvas()->place(self::import($doc), new Box(0.0, 0.0, 200.0, 200.0), Fit::entire());
        $doc->addPage(new Dimensions(200.0, 200.0))->place($template, new Box(0.0, 0.0, 200.0, 200.0), Fit::entire());
        $doc->close();

        self::assertStringNotContainsString('/URI', $out->contents());
    }

    #[Test]
    public function importsWithAndWithoutAnnotationsAreKeptApart(): void
    {
        $doc  = new Document(new MemoryOutput());
        $file = PdfFile::fromData(AnnotatedPdf::pdf());

        self::assertSame(
            [false, true],
            [
                $doc->import($file) === $doc->import($file, annotations: ImportedAnnotations::Flatten),
                $doc->import($file, annotations: ImportedAnnotations::Flatten)
                === $doc->import($file, annotations: ImportedAnnotations::Flatten),
            ],
        );
    }

    #[Test]
    public function pdfXLeavesImportedLinksOut(): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(
            compress: false,
            pdfx: PdfX::X4,
        ));
        $doc->setOutputIntent(StubIccProfile::of('CMYK'), 'Test condition');
        $doc->info()->setTitle('Imported annotations');
        $doc->addPage(new Dimensions(200.0, 200.0))->place(
            self::import($doc),
            new Box(0.0, 0.0, 200.0, 200.0),
            Fit::entire(),
        );
        $doc->close();

        self::assertStringNotContainsString('/URI', $out->contents());
    }

    #[Test]
    #[DataProvider('rendererProvider')]
    public function theAnnotationsThatPrintAreDrawnWhereTheyPrint(string $renderer): void
    {
        $path = PdfTools::outputDir() . "/imported-annotations-{$renderer}.pdf";
        $doc  = new Document($path);
        $page = $doc->import(
            PdfFile::fromData(AnnotatedPdf::pdf()),
            HiddenLayers::Remove,
            ImportedAnnotations::Flatten,
        )->page(1);
        $doc->addPage(new Dimensions(200.0, 200.0))->place($page, new Box(0.0, 0.0, 200.0, 200.0), Fit::entire());
        $doc->close();
        PdfTools::assertValid($path);
        $png = imagecreatefrompng(match ($renderer) {
            'ghostscript'  => PdfTools::ghostscriptRender($path, dpi: 72),
            'coregraphics' => PdfTools::coreGraphicsRender($path),
            default        => PdfTools::render($path, dpi: 72),
        });
        self::assertNotFalse($png);

        self::assertSame(
            ['red', 'white', 'white', 'magenta', 'yellow', 'white', 'white'],
            [
                self::colour($png, 40, 160),
                self::colour($png, 100, 160),
                self::colour($png, 160, 160),
                self::colour($png, 40, 100),
                self::colour($png, 100, 80),
                self::colour($png, 40, 40),
                self::colour($png, 160, 100),
            ],
            'Red, blue (not printing), green (hidden), magenta (its state), yellow (turned), cyan (in a removed layer), the link.',
        );
    }
}
