<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Geometry\Dimensions;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function pathinfo;

use const PATHINFO_FILENAME;

#[Group('integration')]
final class CffSubsetTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function cffFontProvider(): array
    {
        return [
            'name-keyed' => ['fonts/SourceSans3-Regular.otf'],
            'CID-keyed'  => ['fonts/PhpPdfCidTest.otf'],
        ];
    }

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

    /**
     * macOS's renderer applies only the Top DICT's font matrix, so a subset whose matrix sits in a
     * Font DICT under an identity Top DICT draws its glyphs a thousand times too large, off the page.
     */
    #[Test]
    #[DataProvider('cffFontProvider')]
    public function subsetCffTextIsVisibleInMacOs(string $asset): void
    {
        $path = PdfTools::outputDir() . '/cff-' . pathinfo($asset, PATHINFO_FILENAME) . '.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->addPage(new Dimensions(600.0, 120.0))
            ->canvas()
            ->drawText('object jest Templates', $doc->fonts()->load(PdfTools::assetPath($asset)), 60.0, 10.0, 40.0);
        $doc->close();

        self::assertGreaterThan(500, PdfTools::coreGraphicsInk($path));
    }
}
