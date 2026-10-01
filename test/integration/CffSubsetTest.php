<?php

declare(strict_types=1);

namespace PhpPdfTest\Integration;

use PhpPdf\Core\FixedClock;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\PageSize;
use PhpPdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class CffSubsetTest extends TestCase
{
    #[Test]
    public function subsetCffFontsProduceAValidFileWithExtractableText(): void
    {
        $path = PdfTools::outputDir() . '/cff-subset.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $c    = $doc->addPage(PageSize::A4)->canvas();
        $c->drawText(
            'Name-keyed CFF: Grüße, 12 € — Ŧħę qüïçķ ƀŗøŵñ',
            $doc->fonts()->load(PdfTools::assetPath('fonts/SourceSans3-Regular.otf')),
            20,
            50,
            780,
        );
        $c->drawText(
            'CID-keyed CFF: The QUICK brown fox',
            $doc->fonts()->load(PdfTools::assetPath('fonts/PhpPdfCidTest.otf')),
            20,
            50,
            740,
        );
        $doc->close();

        PdfTools::assertValid($path);
        self::assertSame([], $doc->fonts()->warnings());
        if (! PdfTools::hasPoppler()) {
            self::markTestSkipped('poppler is not installed; text extraction not checked.');
        }
        $text = PdfTools::text($path);
        self::assertStringContainsString('Name-keyed CFF: Grüße, 12 € — Ŧħę qüïçķ ƀŗøŵñ', $text);
        self::assertStringContainsString('CID-keyed CFF: The QUICK brown fox', $text);
    }
}
