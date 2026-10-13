<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Color\Gray;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Fit\Position;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Import\HiddenLayers;
use Php2Pdf\Import\PageBox;
use Php2Pdf\Import\PdfFile;
use Php2PdfTest\TestAsset\LayeredPdf;
use Php2PdfTest\TestAsset\PdfTools;
use Php2PdfTest\TestAsset\Visual;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function intdiv;
use function substr_count;

#[Group('integration')]
final class ImportTest extends TestCase
{
    /**
     * A layer switched off in the source stays off. CoreGraphics (Preview) is not checked: it
     * ignores optional content inside form XObjects, and imported pages are forms.
     */
    #[Test]
    public function keepsHiddenLayersHidden(): void
    {
        $path = PdfTools::outputDir() . '/import-layers.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->addPage(new Dimensions(100.0, 100.0))->place(
            $doc->import(PdfFile::fromData(LayeredPdf::make()))->page(1),
            new Box(0.0, 0.0, 100.0, 100.0),
        );
        $doc->close();

        PdfTools::assertValid($path);
        self::assertSame(
            ['poppler' => 0, 'Ghostscript' => 0],
            [
                'poppler'     => PdfTools::ink(PdfTools::render($path)),
                'Ghostscript' => PdfTools::ink(PdfTools::ghostscriptRender($path)),
            ],
        );
    }

    #[Test]
    public function placesImportedPagesAsTemplates(): void
    {
        $path = PdfTools::outputDir() . '/import.pdf';
        $doc  = new Document($path, new DocumentOptions(
            clock: new FixedClock('2026-01-01T12:00:00Z'),
            objectStreams: false,
        ));
        $font = $doc->fonts()->load(PdfTools::assetPath('fonts/SourceSans3-Regular.otf'));

        $classic   = $doc->import(PdfTools::assetPath('pdf/classic.pdf'));
        $objstm    = $doc->import(PdfTools::assetPath('pdf/objstm.pdf'));
        $inherited = $doc->import(PdfTools::assetPath('pdf/inherited.pdf'));
        $magick    = $doc->import(PdfTools::assetPath('pdf/imagemagick.pdf'));
        $cairo     = $doc->import(PdfTools::assetPath('pdf/cairo.pdf'));

        $page = $doc->addPage(PageSize::A4);
        $c    = $page->canvas();
        $c->setFillColor(Gray::black());
        $cells = [
            ['classic p1 crop', $classic->page(1)],
            ['classic p2 rotated 90', $classic->page(2)],
            ['classic p1 trim box', $classic->page(1, PageBox::Trim)],
            ['objstm p1 (same file, written once)', $objstm->page(1)],
            ['inherited p1', $inherited->page(1)],
            ['inherited p2 (rotate 90 inherited)', $inherited->page(2)],
            ['imagemagick', $magick->page(1)],
            ['cairo p2', $cairo->page(2)],
        ];
        foreach ($cells as $i => [$label, $template]) {
            $x = 30.0 + (($i % 3) * 180.0);
            $y = 620.0 - (intdiv($i, num2: 3) * 220.0);
            $c->setStrokeColor(Gray::black())->setLineWidth(0.3)->strokeRect($x, $y, 160.0, 180.0);
            $c->place($template, new Box($x, $y, 160.0, 180.0), Fit::meet(Position::center()));
            $c->drawText($label, $font, 7.0, $x, $y - 9.0);
        }

        // Shared resources: placing a page again writes nothing new.
        $second = $doc->addPage(PageSize::A4, topDown: true);
        $second->place($classic->page(1), new Box(20.0, 20.0, 300.0, 400.0), Fit::meet(Position::topLeft()));
        $second->place($classic->page(1), new Box(330.0, 20.0, 240.0, 400.0), Fit::entire(15.0));
        $doc->close();

        PdfTools::assertValid($path);
        $pdf = (string) file_get_contents($path);
        // One TrueType program each from classic.pdf, objstm.pdf and cairo.pdf (the classic file's
        // font is written once although its pages are placed four times), and the CFF label font.
        self::assertSame(
            [3, 1],
            [substr_count($pdf, needle: '/FontFile2'), substr_count($pdf, needle: '/FontFile3')],
        );
        self::assertStringContainsString('Page 1 of the import corpus', PdfTools::text($path, 2));
        Visual::assertMatchesBaseline($path, 'import-p1', 1);
        Visual::assertMatchesBaseline($path, 'import-p2', 2);
    }

    /** With hidden layers removed, no renderer shows them, macOS Preview included. */
    #[Test]
    public function removedHiddenLayersShowNowhere(): void
    {
        $path = PdfTools::outputDir() . '/import-layers-removed.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->addPage(new Dimensions(100.0, 100.0))->place(
            $doc->import(PdfFile::fromData(LayeredPdf::make()), HiddenLayers::Remove)->page(1),
            new Box(0.0, 0.0, 100.0, 100.0),
        );
        $doc->close();

        PdfTools::assertValid($path);
        self::assertSame(
            ['poppler' => 0, 'Ghostscript' => 0, 'CoreGraphics' => 0],
            [
                'poppler'      => PdfTools::ink(PdfTools::render($path)),
                'Ghostscript'  => PdfTools::ink(PdfTools::ghostscriptRender($path)),
                'CoreGraphics' => PdfTools::coreGraphicsInk($path),
            ],
        );
    }
}
