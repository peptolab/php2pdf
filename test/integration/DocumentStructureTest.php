<?php

declare(strict_types=1);

namespace PhpPdfTest\Integration;

use PhpPdf\Core\FixedClock;
use PhpPdf\Document\Document;
use PhpPdf\Document\Dimensions;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\PageSize;
use PhpPdf\Geometry\Box;
use PhpPdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class DocumentStructureTest extends TestCase
{
    #[Test]
    public function multiPageDocumentValidatesAndReportsBoxes(): void
    {
        $path = PdfTools::outputDir() . '/structure.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->info()->setTitle('Structure test');

        for ($i = 1; $i <= 3; ++$i) {
            $page = $doc->addPage(Dimensions::mm(216, 303));
            $page->setBleedBox(Box::fromMm(0, 0, 216, 303));
            $page->setTrimBox(Box::fromMm(3, 3, 210, 297));
            $page->setCropBox(Box::fromMm(1, 1, 214, 301));
            $page->canvas()->fillRect(72, 72, 72, 72);
        }
        $doc->addPage(PageSize::A5->landscape())->setRotation(270);
        $doc->close();

        PdfTools::assertValid($path);
        $info = PdfTools::info($path);
        self::assertSame('4', $info['Pages']);
        self::assertSame('Structure test', $info['Title']);
        self::assertSame('606.614 x 853.228 pts', $info['Page size']);
        self::assertSame(['2.83', '2.83', '609.45', '856.06'], preg_split('/\s+/', trim($info['CropBox'])));
        self::assertSame(['0.00', '0.00', '612.28', '858.90'], preg_split('/\s+/', trim($info['BleedBox'])));
        self::assertSame(['8.50', '8.50', '603.78', '850.39'], preg_split('/\s+/', trim($info['TrimBox'])));
    }
}
