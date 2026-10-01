<?php

declare(strict_types=1);

namespace PhpPdfTest\Integration;

use PhpPdf\Color\Gray;
use PhpPdf\Core\FixedClock;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\PageSize;
use PhpPdf\Fit\Fit;
use PhpPdf\Fit\Position;
use PhpPdf\Geometry\Box;
use PhpPdf\Import\PageBox;
use PhpPdfTest\Support\PdfTools;
use PhpPdfTest\Support\Visual;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function intdiv;
use function substr_count;

final class ImportTest extends TestCase
{
    #[Test]
    public function placesImportedPagesAsTemplates(): void
    {
        $path = PdfTools::outputDir() . '/import.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $font = $doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));

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
        // One font program each from classic.pdf, objstm.pdf and cairo.pdf, plus the label font: the
        // classic file's font is written once although its pages are placed four times.
        self::assertSame(4, substr_count($pdf, needle: '/FontFile2'));
        self::assertStringContainsString('Page 1 of the import corpus', PdfTools::text($path, 2));
        Visual::assertMatchesBaseline($path, 'import-p1', 1);
        Visual::assertMatchesBaseline($path, 'import-p2', 2);
    }
}
