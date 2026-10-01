<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Font;

use PhpPdf\Core\FixedClock;
use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\PageSize;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function glob;
use function is_file;

/**
 * CFF-flavoured OpenType fonts are embedded whole as FontFile3/OpenType with a CIDFontType0
 * descendant. The test uses whichever .otf the system provides and is skipped without one.
 */
final class CffEmbeddingTest extends TestCase
{
    #[Test]
    public function embedsCffFontsWholeAsCidFontType0(): void
    {
        $candidates = glob('/usr/share/fonts/opentype/*/*.otf');
        $path       = false === $candidates ? null : $candidates[0] ?? null;
        if (null === $path || ! is_file($path)) {
            self::markTestSkipped('No CFF OpenType font installed.');
        }
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(
            compress: false,
            clock: new FixedClock('2026-01-01T00:00:00Z'),
        ));
        $font = $doc->fonts()->load($path);
        $doc->addPage(PageSize::A4)->canvas()->drawText('Hello', $font, 12.0, 10.0, 10.0);
        $doc->close();
        $pdf = $out->contents();

        self::assertTrue($font->program()->isCff);
        self::assertStringContainsString('/Subtype/CIDFontType0', $pdf);
        self::assertStringContainsString('/FontFile3', $pdf);
        self::assertStringContainsString('/Subtype/OpenType', $pdf);
        self::assertStringContainsString('/Encoding/Identity-H', $pdf);
        self::assertStringNotContainsString('/CIDToGIDMap', $pdf);
    }
}
