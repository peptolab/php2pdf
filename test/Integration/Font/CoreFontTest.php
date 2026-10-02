<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Document\PdfX;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Font\CoreFont;
use Php2Pdf\Font\StandardFont;
use Php2Pdf\Font\TextOptions;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Table\Column;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\Textflow;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('integration')]
final class CoreFontTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;

    /** @return array<string, array{0: string, 1: StandardFont}> */
    public static function standardNameProvider(): array
    {
        return [
            'exact name'           => ['Helvetica', StandardFont::Helvetica],
            'hyphenated style'     => ['Helvetica-BoldOblique', StandardFont::HelveticaBoldOblique],
            'spaced and lowercase' => ['times bold italic', StandardFont::TimesBoldItalic],
            'roman'                => ['Times-Roman', StandardFont::TimesRoman],
            'courier'              => ['courier_oblique', StandardFont::CourierOblique],
            'symbol'               => ['Symbol', StandardFont::Symbol],
            'dingbats'             => ['Zapf Dingbats', StandardFont::ZapfDingbats],
        ];
    }

    #[Test]
    public function charactersOutsideWinAnsiAreRejected(): void
    {
        $font = $this->doc->fonts()->standard(StandardFont::Helvetica);

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('cannot show "中" (U+4E2D)');
        $font->shape('a中');
    }

    #[Test]
    #[DataProvider('standardNameProvider')]
    public function findResolvesStandardFontNames(string $name, StandardFont $expected): void
    {
        $font = $this->doc->fonts()->find($name);

        self::assertInstanceOf(CoreFont::class, $font);
        self::assertSame([$expected, $expected->value], [$font->font(), $font->name()]);
    }

    #[Test]
    public function findReturnsOneInstancePerStandardFont(): void
    {
        self::assertSame(
            $this->doc->fonts()->find('Helvetica'),
            $this->doc->fonts()->standard(StandardFont::Helvetica),
        );
    }

    #[Test]
    public function fontFilesInTheSearchPathsWinOverStandardFonts(): void
    {
        $this->doc->fonts()->addSearchPath(PdfTools::assetPath('fonts'));

        self::assertNotInstanceOf(CoreFont::class, $this->doc->fonts()->find('DejaVu Sans'));
    }

    #[Test]
    public function invalidUtf8IsSkipped(): void
    {
        $font = $this->doc->fonts()->standard(StandardFont::Helvetica);

        self::assertSame(1, $font->shape("a\xFF")->count());
    }

    #[Test]
    public function kerningComesFromTheAfmPairs(): void
    {
        $font = $this->doc->fonts()->standard(StandardFont::Helvetica);

        // A 667 + V 667, kerned by -70.
        self::assertEqualsWithDelta(12.64, $font->width('AV', 10.0), 1.0e-9);
    }

    #[Test]
    public function latinFontsAreWrittenAsSimpleType1WithWinAnsiEncoding(): void
    {
        $font = $this->doc->fonts()->find('Helvetica-Bold');
        $this->doc->addPage(PageSize::A4)->canvas()->drawText('Hi!', $font, 12.0, 10.0, 10.0);
        $this->doc->close();
        $pdf = $this->out->contents();

        self::assertStringContainsString(
            '<</Type/Font/Subtype/Type1/BaseFont/Helvetica-Bold/Encoding/WinAnsiEncoding>>',
            $pdf,
        );
        self::assertStringContainsString('(Hi!) Tj', $pdf);
        self::assertStringNotContainsString('/FontFile', $pdf);
    }

    #[Test]
    public function pdfXRefusesStandardFonts(): void
    {
        $doc = new Document(new MemoryOutput(), new DocumentOptions(pdfx: PdfX::X4));

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('PDF/X-4 requires every font to be embedded');
        $doc->fonts()->find('Helvetica');
    }

    #[Test]
    public function repeatedTextReusesItsShapedRun(): void
    {
        $font = $this->doc->fonts()->standard(StandardFont::Helvetica);

        self::assertSame($font->shape('Wave'), $font->shape('Wave'));
    }

    #[Test]
    public function symbolicFontsCarryAToUnicodeMap(): void
    {
        $font = $this->doc->fonts()->find('ZapfDingbats');
        $this->doc->addPage(PageSize::A4)->canvas()->drawText('✁', $font, 12.0, 10.0, 10.0);
        $this->doc->close();
        $pdf = $this->out->contents();

        self::assertStringNotContainsString('/Encoding', $pdf);
        self::assertStringContainsString("<00> <FF>\nendcodespacerange", $pdf);
        self::assertStringContainsString('<21> <2701>', $pdf);
    }

    #[Test]
    public function symbolUsesItsBuiltInEncoding(): void
    {
        $font = $this->doc->fonts()->standard(StandardFont::Symbol);
        $run  = $font->shape('α');

        self::assertSame([0x61, 631], [$run->glyphs[0]->id, $run->glyphs[0]->advance]);
    }

    #[Test]
    public function textflowsTextlinesAndTablesLayOutWithStandardFontsByName(): void
    {
        $style = new TextStyle(
            font: 'Helvetica',
            size: 10.0,
        );
        $flow  = Textflow::fromText($this->doc->text(), 'Standard fonts flow like any other.', $style);
        $table = $this->doc->table([Column::auto(), Column::auto()], null, $style);
        $table->addRow(['Reference', 'Total']);
        $page = $this->doc->addPage(PageSize::A4);

        $line = $page->fitTextline(
            'Invoice',
            $this->doc->fonts()->find('Helvetica'),
            10.0,
            new Box(10.0, 300.0, 300.0, 20.0),
        );

        self::assertSame(
            [FlowResult::Done, FlowResult::Done, true],
            [
                $page->fitTextflow($flow, new Box(10.0, 600.0, 300.0, 100.0)),
                $page->fitTable($table, new Box(10.0, 400.0, 300.0, 100.0)),
                $line->fits,
            ],
        );
    }

    #[Test]
    public function textIsEncodedAsWinAnsiBytes(): void
    {
        $font = $this->doc->fonts()->standard(StandardFont::TimesRoman);
        $run  = $font->shape('é’€', new TextOptions(kerning: false));

        self::assertSame([0xE9, 0x92, 0x80], [$run->glyphs[0]->id, $run->glyphs[1]->id, $run->glyphs[2]->id]);
    }

    #[Test]
    public function theShapeCacheEmptiesWhenFull(): void
    {
        $font  = $this->doc->fonts()->standard(StandardFont::Helvetica);
        $first = $font->shape('first');
        for ($i = 0; $i < 10_000; ++$i) {
            $font->shape("w{$i}");
        }

        self::assertNotSame($first, $font->shape('first'));
    }

    #[Test]
    public function unknownNamesAreStillRejected(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('not one of the standard 14 fonts');
        $this->doc->fonts()->find('Helvetica Neue');
    }

    #[Test]
    public function verticalMetricsComeFromTheAfmHeader(): void
    {
        $font = $this->doc->fonts()->standard(StandardFont::Helvetica);

        self::assertEqualsWithDelta(
            [7.18, -2.07, 7.18, 5.23, -1.0, 0.5, 0.0, 2.5, 0.5],
            [
                $font->ascender(10.0),
                $font->descender(10.0),
                $font->capHeight(10.0),
                $font->xHeight(10.0),
                $font->underlinePosition(10.0),
                $font->underlineThickness(10.0),
                $font->lineGap(10.0),
                $font->strikeoutPosition(10.0),
                $font->strikeoutSize(10.0),
            ],
            1.0e-9,
        );
    }

    #[Test]
    public function widthsComeFromTheAfmMetrics(): void
    {
        $font = $this->doc->fonts()->standard(StandardFont::Helvetica);

        // H e l l o space W o r l d: 722 + 556 + 222 + 222 + 556 + 278 + 944 + 556 + 333 + 222 + 556
        self::assertEqualsWithDelta(62.004, $font->width('Hello World', 12.0, new TextOptions(kerning: false)), 1.0e-9);
    }

    protected function setUp(): void
    {
        $this->out = new MemoryOutput();
        $this->doc = new Document($this->out, new DocumentOptions(
            compress: false,
            clock: new FixedClock('2026-01-01T00:00:00Z'),
        ));
    }
}
