<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PdfX;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\Font;
use Php2Pdf\Font\GlyphCheck;
use Php2Pdf\Font\TextOptions;
use Php2Pdf\Font\TextRenderMode;
use Php2Pdf\Font\ToUnicode;
use Php2PdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class FontTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;
    private Font $font;

    /** @return array<string, array{0: DocumentOptions, 1: string|null, 2: int}> */
    public static function glyphCheckProvider(): array
    {
        $error = 'Font "DejaVuSans" has no glyph for "中" (U+4E2D).';

        return [
            'PDF/X defaults to an error' => [new DocumentOptions(pdfx: PdfX::X4), $error, 0],
            'explicit error'             => [new DocumentOptions(glyphCheck: GlyphCheck::Error), $error, 0],
            'explicit ignore'            => [new DocumentOptions(glyphCheck: GlyphCheck::Ignore), null, 0],
            'ignore wins under PDF/X'    => [
                new DocumentOptions(
                    pdfx: PdfX::X4,
                    glyphCheck: GlyphCheck::Ignore,
                ),
                null,
                0,
            ],
            'warn by default'            => [new DocumentOptions(), null, 1],
        ];
    }

    #[Test]
    public function aMissingGlyphIsReportedOncePerCharacterByDefault(): void
    {
        $this->font->shape('a中');
        $this->font->shape('中b');

        self::assertSame(
            ['Font "DejaVuSans" has no glyph for "中" (U+4E2D); it is drawn as .notdef.'],
            $this->doc->fonts()->warnings(),
        );
    }

    #[Test]
    public function buildsSurrogatePairsInToUnicode(): void
    {
        $cmap = ToUnicode::build([3 => 0x41, 5 => 0x1_F600]);

        self::assertStringContainsString("2 beginbfchar\n<0003> <0041>\n<0005> <D83DDE00>\nendbfchar", $cmap);
    }

    #[Test]
    public function flipsTextOnTopDownPages(): void
    {
        $this->doc->addPage(topDown: true)->canvas()->drawText('A', $this->font, 12, 10, 20);
        $this->doc->close();

        self::assertStringContainsString('1 0 0 -1 10 20 Tm', $this->out->contents());
    }

    #[Test]
    public function glyphZeroNeverReachesTheToUnicodeMap(): void
    {
        $this->doc->addPage()->canvas()->drawText('a中b', $this->font, 12.0, 10.0, 10.0);
        $this->doc->close();

        self::assertStringContainsString(
            "2 beginbfchar\n<0044> <0061>\n<0045> <0062>\nendbfchar",
            $this->out->contents(),
            'Only a and b are mapped; the missing character is not.',
        );
    }

    #[Test]
    public function kernedAndUnkernedRunsAreCachedApart(): void
    {
        $kerned   = $this->font->shape('AV');
        $unkerned = $this->font->shape('AV', new TextOptions(kerning: false));

        self::assertNotEquals($kerned->width(), $unkerned->width());
    }

    #[Test]
    public function measuresWidthInPoints(): void
    {
        $plain = $this->font->width('AV', 10, new TextOptions(kerning: false));
        self::assertEqualsWithDelta(13.68, $plain, 0.001);
        self::assertEqualsWithDelta(13.68 - 0.6396, $this->font->width('AV', 10), 0.001);
        self::assertEqualsWithDelta(
            13.68 + (2 * 0.5) + (2 * 0.5),
            $this->font->width('AV', 10, new TextOptions(
                kerning: false,
                charSpacing: 0.5,
                tracking: 50,
            )),
            0.001,
        );
        self::assertEqualsWithDelta(
            ($this->font->width('A B', 10) + 3.0) * 0.5,
            $this->font->width('A B', 10, new TextOptions(
                wordSpacing: 3.0,
                horizontalScale: 50,
            )),
            0.001,
        );
    }

    #[Test]
    public function repeatedTextReusesItsShapedRun(): void
    {
        self::assertSame($this->font->shape('Wave'), $this->font->shape('Wave'));
    }

    #[Test]
    public function scalesVerticalMetrics(): void
    {
        self::assertEqualsWithDelta(9.282, $this->font->ascender(10), 0.001);
        self::assertEqualsWithDelta(-2.358, $this->font->descender(10), 0.001);
        self::assertEqualsWithDelta(7.290, $this->font->capHeight(10), 0.001);
        self::assertEqualsWithDelta(5.469, $this->font->xHeight(10), 0.001);
        self::assertEqualsWithDelta(-0.195, $this->font->underlinePosition(10), 0.001);
        self::assertEqualsWithDelta(0.439, $this->font->underlineThickness(10), 0.001);
    }

    #[Test]
    public function shapesTextWithKerning(): void
    {
        $run = $this->font->shape('AV');

        self::assertSame(2, $run->count());
        self::assertSame(36, $run->glyphs[0]->id);
        self::assertSame(684, $run->glyphs[0]->advance);
        self::assertEqualsWithDelta(-63.96, $run->glyphs[0]->kerning, 0.01);
        self::assertSame(0.0, $run->glyphs[1]->kerning);
        self::assertSame(0.0, $this->font->shape('AV', new TextOptions(kerning: false))->glyphs[0]->kerning);
    }

    #[Test]
    public function subsetTagIsDeterministic(): void
    {
        $build = static function (): string {
            $out = new MemoryOutput();
            $doc = new Document($out, new DocumentOptions(
                clock: new FixedClock('2026-01-01'),
                xmp: false,
            ));
            $font = $doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
            $doc->addPage()->canvas()->drawText('Hello', $font, 12, 10, 10);
            $doc->close();

            return $out->contents();
        };

        self::assertSame($build(), $build());
    }

    #[Test]
    #[DataProvider('glyphCheckProvider')]
    public function theGlyphCheckDecidesWhatAMissingGlyphDoes(
        DocumentOptions $options,
        ?string $error,
        int $warnings,
    ): void {
        $doc  = new Document(new MemoryOutput(), $options);
        $font = $doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        if (null !== $error) {
            $this->expectException(PdfException::class);
            $this->expectExceptionMessage($error);
        }
        $font->shape('中');

        self::assertCount($warnings, $doc->fonts()->warnings());
    }

    #[Test]
    public function theShapeCacheEmptiesWhenFull(): void
    {
        $first = $this->font->shape('first');
        for ($i = 0; $i < 10_000; ++$i) {
            $this->font->shape("w{$i}");
        }

        self::assertNotSame($first, $this->font->shape('first'));
    }

    #[Test]
    public function writesType0FontWithSubsetWidthsAndToUnicode(): void
    {
        $canvas = $this->doc->addPage()->canvas();
        $canvas->drawText('AV', $this->font, 12, 10, 20, new TextOptions(
            wordSpacing: 2,
            tracking: 10,
            charSpacing: 1,
        ));
        $canvas->drawText('B C', $this->font, 12, 10, 40, new TextOptions(
            renderMode: TextRenderMode::Stroke,
            wordSpacing: 6,
        ));
        $this->doc->close();
        $pdf = $this->out->contents();

        self::assertStringContainsString(
            "BT /F1 12 Tf 1 Tc 10 20 Td [(\x00\x24) 53.96 (\x00\x39) -10] TJ ET",
            $pdf,
        );
        self::assertStringContainsString(
            "BT 0 Tc 1 Tr 10 40 Td [(\x00\x25\x00\x03) -500 (\x00\x26)] TJ ET",
            $pdf,
            'Only the text state that changed is set again, including back to its default.',
        );
        self::assertMatchesRegularExpression(
            '/\/Subtype\/Type0\/BaseFont\/[A-Z]{6}\+DejaVuSans\/Encoding\/Identity-H/',
            $pdf,
        );
        self::assertStringContainsString('/Subtype/CIDFontType2', $pdf);
        self::assertStringContainsString('/CIDToGIDMap/Identity', $pdf);
        self::assertStringContainsString('/DW 1000/W[0 [600] 3 [318] 36 [684 686 698] 57 [684]]', $pdf);
        self::assertStringContainsString(
            '/Flags 32/FontBBox[-1021 -463 1793 1232]/ItalicAngle 0/Ascent 928/Descent -236/CapHeight 729',
            $pdf,
        );
        self::assertStringContainsString('/Length1 ', $pdf);
        self::assertStringContainsString(
            "5 beginbfchar\n<0003> <0020>\n<0024> <0041>\n<0025> <0042>\n<0026> <0043>\n<0039> <0056>\nendbfchar",
            $pdf,
        );
    }

    protected function setUp(): void
    {
        $this->out = new MemoryOutput();
        $this->doc = new Document($this->out, new DocumentOptions(
            clock: new FixedClock('2026-01-01'),
            compress: false,
            xmp: false,
        ));
        $this->font = $this->doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
    }
}
