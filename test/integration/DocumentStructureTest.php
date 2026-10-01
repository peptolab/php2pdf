<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Destination;
use Php2Pdf\Document\Dimensions;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Geometry\Box;
use Php2PdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_split;
use function trim;

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

    /** Readers find named destinations in the name tree that links and bookmarks refer to. */
    #[Test]
    public function namedDestinationsResolve(): void
    {
        $path = PdfTools::outputDir() . '/named-destinations.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->addPage(PageSize::A4)->addLink(new Box(72.0, 72.0, 100.0, 20.0), Destination::named('chapter-2'));
        $doc->addPage(PageSize::A4);
        $doc->addDestination('chapter-2', Destination::top(2, 700.0));
        $doc->outline()->add('Chapter 2', Destination::named('chapter-2'));
        $doc->close();

        PdfTools::assertValid($path);
        [$code, $output] = PdfTools::run(['pdfinfo', '-dests', $path]);
        self::assertSame(0, $code, $output);
        self::assertMatchesRegularExpression('/^\s*2\s+\[ FitH\s+700\s*\]\s+"chapter-2"/m', $output);
    }
}
