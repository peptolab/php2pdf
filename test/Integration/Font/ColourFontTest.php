<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font;

use Php2Pdf\Color\Rgb;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Font\Font;
use Php2Pdf\Font\TextOptions;
use Php2Pdf\Font\TextRenderMode;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function preg_match_all;
use function substr_count;

/**
 * Colour fonts (#39). In the test font, "A" is drawn as a red "O", a half-transparent blue "I"
 * and an "A" in the text colour; "B" is an ordinary glyph.
 */
#[Group('integration')]
final class ColourFontTest extends TestCase
{
    private Document $doc;
    private MemoryOutput $out;
    private Font $font;

    #[Test]
    public function aColourGlyphHasItsLayersWithTheirPaletteColours(): void
    {
        $program = $this->font->program();

        self::assertEquals(
            [
                0 => [
                    [$program->glyphId(0x4F), new Rgb(1.0, 0.0, 0.0), 1.0],
                    [$program->glyphId(0x49), new Rgb(0.0, 0.0, 1.0), 128 / 255],
                    [$program->glyphId(0x41), null, 1.0],
                ],
            ],
            $this->font->colourLayers($this->font->shape('AB')),
        );
    }

    #[Test]
    public function aFontWithOnlyBitmapsIsRefused(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('has no outlines (glyf, CFF or CFF2): its glyphs are sbix bitmaps');
        $this->doc->fonts()->load(PdfTools::assetPath('fonts/PhpPdfBitmapOnly.ttf'));
    }

    #[Test]
    public function colrVersion1GlyphsAreDrawnInOneColourWithAWarning(): void
    {
        $font = $this->doc->fonts()->load(PdfTools::assetPath('fonts/PhpPdfColourV1Test.ttf'));

        self::assertSame(
            [
                [],
                [
                    'Font "PhpPdfColourV1Test" has colour glyphs defined only in COLR version 1; they are drawn in one colour.',
                ],
            ],
            [$font->colourLayers($font->shape('A')), $this->doc->fonts()->warnings()],
        );
    }

    #[Test]
    public function invisibleTextDrawsNoLayers(): void
    {
        $this->doc
            ->addPage(PageSize::A4)
            ->canvas()
            ->drawText('A', $this->font, 24.0, 10.0, 10.0, new TextOptions(renderMode: TextRenderMode::Invisible));
        $this->doc->close();

        self::assertSame(0, substr_count($this->out->contents(), needle: "\nq\n"));
    }

    #[Test]
    public function layerGlyphsAddNothingToTheCopiedText(): void
    {
        $this->doc->addPage(PageSize::A4)->canvas()->drawText('A', $this->font, 24.0, 10.0, 10.0);
        $this->doc->close();
        $match = [];
        preg_match('/(\d+) beginbfchar/', $this->out->contents(), $match);

        self::assertSame('1', $match[1] ?? null, 'Only the base glyph maps to text.');
    }

    #[Test]
    public function theBaseGlyphIsShownInvisiblyUnderItsLayers(): void
    {
        $this->doc->addPage(PageSize::A4)->canvas()->drawText('AB', $this->font, 24.0, 10.0, 10.0);
        $this->doc->close();
        $pdf = $this->out->contents();

        self::assertSame(
            [1, 3, 1],
            [
                substr_count($pdf, needle: ' 3 Tr '),
                preg_match_all('/\nq\n.*?BT .*? Tj ET\nQ/s', $pdf),
                preg_match('#/ca 0\.5\d*#', $pdf),
            ],
        );
    }

    protected function setUp(): void
    {
        $this->out  = new MemoryOutput();
        $this->doc  = new Document($this->out, new DocumentOptions(compress: false));
        $this->font = $this->doc->fonts()->load(PdfTools::assetPath('fonts/PhpPdfColourTest.ttf'));
    }
}
