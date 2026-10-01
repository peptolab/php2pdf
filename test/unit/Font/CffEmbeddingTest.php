<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Font;

use PhpPdf\Core\FixedClock;
use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\PageSize;
use PhpPdf\Font\Font;
use PhpPdf\Font\OpenType\OpenTypeFont;
use PhpPdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function strlen;
use function substr;
use function substr_replace;
use function unpack;

/**
 * CFF-flavoured OpenType fonts are subset to a bare CID-keyed CFF (FontFile3 of subtype
 * CIDFontType0C) under a CIDFontType0 descendant, and embedded whole when subsetting fails.
 */
#[Group('unit')]
final class CffEmbeddingTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;

    /** @return array<string, array{0: string}> */
    public static function cffFontProvider(): array
    {
        return [
            'name-keyed' => ['fonts/SourceSans3-Regular.otf'],
            'CID-keyed'  => ['fonts/PhpPdfCidTest.otf'],
        ];
    }

    private static function tableOffset(string $font, string $tag): int
    {
        $count = (int) (unpack('n', $font, offset: 4)[1] ?? 0);
        for ($i = 0; $i < $count; ++$i) {
            $record = 12 + (16 * $i);
            if (substr($font, $record, length: 4) === $tag) {
                return (int) (unpack('N', $font, $record + 8)[1] ?? 0);
            }
        }
        self::fail("No {$tag} table.");
    }

    #[Test]
    public function aFontThatCannotBeSubsetIsEmbeddedWholeWithAWarning(): void
    {
        $data    = (string) file_get_contents(PdfTools::assetPath('fonts/PhpPdfCidTest.otf'));
        $program = OpenTypeFont::fromData(substr_replace(
            $data,
            replace: "\x02",
            offset: self::tableOffset($data, 'CFF '),
            length: 1,
        ));
        $pdf = $this->draw($this->doc->fonts()->embed($program));

        self::assertStringContainsString('/Subtype/OpenType', $pdf);
        self::assertSame(
            [
                'Font "PhpPdfCidTest-Regular" is embedded whole because its CFF outlines could not be subset: '
                    . 'CFF version 2 is not supported.',
            ],
            $this->doc->fonts()->warnings(),
        );
    }

    #[Test]
    #[DataProvider('cffFontProvider')]
    public function embedsASubsetAsCidFontType0C(string $asset): void
    {
        $pdf = $this->draw($this->doc->fonts()->load(PdfTools::assetPath($asset)));

        foreach ([
            '/Subtype/CIDFontType0/',
            '/FontFile3',
            '/Subtype/CIDFontType0C',
            '/Encoding/Identity-H',
        ] as $expected) {
            self::assertStringContainsString($expected, $pdf);
        }
        self::assertStringNotContainsString('/CIDToGIDMap', $pdf);
    }

    #[Test]
    public function subsettingRecordsNoWarnings(): void
    {
        $this->draw($this->doc->fonts()->load(PdfTools::assetPath('fonts/SourceSans3-Regular.otf')));

        self::assertSame([], $this->doc->fonts()->warnings());
    }

    #[Test]
    public function theSubsetIsAFractionOfTheFont(): void
    {
        $path = PdfTools::assetPath('fonts/SourceSans3-Regular.otf');
        $pdf  = $this->draw($this->doc->fonts()->load($path));

        self::assertLessThan(strlen((string) file_get_contents($path)) / 10, strlen($pdf));
    }

    protected function setUp(): void
    {
        $this->out = new MemoryOutput();
        $this->doc = new Document($this->out, new DocumentOptions(
            compress: false,
            clock: new FixedClock('2026-01-01T00:00:00Z'),
        ));
    }

    private function draw(Font $font): string
    {
        $this->doc->addPage(PageSize::A4)->canvas()->drawText('Hello', $font, 12.0, 10.0, 10.0);
        $this->doc->close();

        return $this->out->contents();
    }
}
