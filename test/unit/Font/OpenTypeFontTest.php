<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font;

use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2Pdf\Font\OpenType\TrueTypeSubsetter;
use Php2PdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function max;
use function str_repeat;
use function strlen;
use function unpack;

final class OpenTypeFontTest extends TestCase
{
    /** @return array<string, array{0: int, 1: int}> */
    public static function sideBearingProvider(): array
    {
        return [
            'negative, j'                    => [77, -37],
            'last full metric'               => [6237, 165],
            'first of the trailing bearings' => [6238, 165],
            'last of the trailing bearings'  => [6252, 151],
            'negative glyph id'              => [-1, 0],
            'glyph id beyond the font'       => [99_999, 0],
        ];
    }

    private static function checksum(string $data): int
    {
        $data .= str_repeat("\0", (4 - (strlen($data) % 4)) % 4);
        $sum  = 0;
        foreach (unpack('N*', $data) as $word) {
            $sum = ($sum + $word) & 0xFFFF_FFFF;
        }

        return ($sum - 0xB1B0_AFBA) & 0xFFFF_FFFF;
    }

    private static function dejaVu(): OpenTypeFont
    {
        return OpenTypeFont::fromFile(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
    }

    #[Test]
    public function mapsCodePointsAndAdvances(): void
    {
        $font = self::dejaVu();

        self::assertSame(36, $font->glyphId(0x41));
        self::assertSame(1401, $font->advance(36));
        self::assertTrue($font->hasGlyph(0x20AC));
        self::assertSame(0, $font->glyphId(0x3400));
        self::assertFalse($font->hasGlyph(0x3400));
    }

    #[Test]
    public function readsCompositeComponents(): void
    {
        $font   = self::dejaVu();
        $aGrave = $font->glyphId(0xC0);

        $components = $font->componentGlyphs($aGrave);
        self::assertContains($font->glyphId(0x41), $components);
        self::assertCount(2, $components);
        self::assertSame([], $font->componentGlyphs($font->glyphId(0x41)));
    }

    #[Test]
    public function readsHeaderMetricsAndNames(): void
    {
        $font = self::dejaVu();

        self::assertSame(2048, $font->unitsPerEm);
        self::assertSame(6253, $font->numGlyphs);
        self::assertSame(1901, $font->metrics->ascender);
        self::assertSame(-483, $font->metrics->descender);
        self::assertSame(0, $font->metrics->lineGap);
        self::assertSame(-40, $font->metrics->underlinePosition);
        self::assertSame(90, $font->metrics->underlineThickness);
        self::assertSame('DejaVu Sans', $font->familyName());
        self::assertSame('Book', $font->subfamilyName());
        self::assertSame('DejaVuSans', $font->postScriptName());
        self::assertFalse($font->metrics->isBold);
        self::assertFalse($font->metrics->isItalic);
        self::assertFalse($font->isCff);
        // OS/2 version 1 lacks cap and x heights, so they are measured from H and x.
        self::assertSame(1493, $font->metrics->capHeight);
        self::assertSame(1120, $font->metrics->xHeight);
    }

    #[Test]
    #[DataProvider('sideBearingProvider')]
    public function readsLeftSideBearings(int $glyphId, int $bearing): void
    {
        self::assertSame($bearing, self::dejaVu()->leftSideBearing($glyphId));
    }

    #[Test]
    public function readsPairKerning(): void
    {
        $font = self::dejaVu();
        $a    = $font->glyphId(0x41);
        $v    = $font->glyphId(0x56);
        $t    = $font->glyphId(0x54);
        $o    = $font->glyphId(0x6F);

        self::assertTrue($font->hasKerning());
        self::assertSame(-131, $font->kerning($a, $v));
        self::assertSame(-348, $font->kerning($t, $o));
        self::assertSame(0, $font->kerning($o, $o));
    }

    #[Test]
    public function rejectsNonFonts(): void
    {
        $this->expectException(PdfException::class);
        OpenTypeFont::fromData(str_repeat('x', times: 100));
    }

    #[Test]
    public function subsetsKeepLeftSideBearings(): void
    {
        $font   = self::dejaVu();
        $subset = OpenTypeFont::fromData(TrueTypeSubsetter::subset($font, [77 => true, 82 => true]));

        self::assertSame(
            [$font->leftSideBearing(77), $font->leftSideBearing(82)],
            [$subset->leftSideBearing(77), $subset->leftSideBearing(82)],
            'Renderers position outlines by the bearing; zeroes shift every glyph by its own bearing.',
        );
    }

    #[Test]
    public function subsetsToUsedGlyphsAndStaysParseable(): void
    {
        $font   = self::dejaVu();
        $used   = [$font->glyphId(0x41) => true, $font->glyphId(0xC0) => true, $font->glyphId(0x20AC) => true];
        $data   = TrueTypeSubsetter::subset($font, $used);
        $subset = OpenTypeFont::fromData($data);

        self::assertLessThan(strlen($font->data()) / 4, strlen($data));
        self::assertSame($font->unitsPerEm, $subset->unitsPerEm);
        $highest = max([...array_keys($used), ...$font->componentGlyphs($font->glyphId(0xC0))]);
        self::assertSame($highest + 1, $subset->numGlyphs);
        self::assertSame($font->advance(36), $subset->advance(36));
        self::assertSame($font->glyphData(36), $subset->glyphData(36));
        // The accent component of À was pulled in; an unused glyph is empty.
        foreach ($font->componentGlyphs($font->glyphId(0xC0)) as $component) {
            self::assertSame($font->glyphData($component), $subset->glyphData($component));
        }
        self::assertSame('', $subset->glyphData($font->glyphId(0x42)));
        self::assertSame(0, self::checksum($data), 'Whole-font checksum must come out as 0xB1B0AFBA.');
    }
}
