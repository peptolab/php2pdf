<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font\OpenType;

use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2Pdf\Font\OpenType\TrueTypeSubsetter;
use Php2PdfTest\TestAsset\Font\SfntTables;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function pack;
use function str_repeat;
use function strlen;
use function substr;
use function substr_replace;
use function unpack;

#[Group('integration')]
final class TrueTypeSubsetterTest extends TestCase
{
    /**
     * DejaVu Sans with its hhea numberOfHMetrics and hmtx table rewritten.
     *
     * @return array<string, array{0: int|null, 1: string|null}> numberOfHMetrics (null: keep), hmtx bytes (null: keep)
     */
    public static function horizontalMetricsProvider(): array
    {
        $font = self::dejaVu();
        $hmtx = (string) $font->table('hmtx');
        // a monospace or CJK style table: 100 long records, then a left side bearing per glyph
        $short = substr($hmtx, offset: 0, length: 400);
        for ($gid = 100; $gid < $font->numGlyphs; ++$gid) {
            $short .= pack('n', $font->leftSideBearing($gid) & 0xFFFF);
        }

        return [
            'every glyph has a long record'           => [null, null],
            'fewer long records than glyphs'          => [100, $short],
            'short bearings cut off mid-value'        => [100, substr($short, offset: 0, length: 400 + 2001)],
            'short bearings cut off before the glyph' => [100, substr($short, offset: 0, length: 1000)],
            'long records cut off mid-record'         => [null, substr($hmtx, offset: 0, length: 41)],
            'more long records than glyphs'           => [$font->numGlyphs + 7, null],
            'an empty hmtx'                           => [null, ''],
            'no long records'                         => [0, null],
        ];
    }

    private static function dejaVu(): OpenTypeFont
    {
        return OpenTypeFont::fromData(self::dejaVuData());
    }

    private static function dejaVuData(): string
    {
        return (string) file_get_contents(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
    }

    #[Test]
    public function aComponentAlsoAskedForKeepsItsOwnPlace(): void
    {
        $font   = self::dejaVu();
        $subset = OpenTypeFont::fromData(TrueTypeSubsetter::subset($font, [
            0,
            $font->glyphId(0x41),
            $font->glyphId(0xC0),
        ]));

        self::assertSame([4, [1, 3]], [$subset->numGlyphs, $subset->componentGlyphs(2)]);
    }

    #[Test]
    public function aCompositeRefersToItsComponentsByTheirNewNumbers(): void
    {
        $font   = self::dejaVu();
        $aGrave = $font->glyphId(0xC0);
        $subset = OpenTypeFont::fromData(TrueTypeSubsetter::subset($font, [0, $aGrave]));

        self::assertSame(
            [4, [2, 3], $font->glyphData($font->glyphId(0x41))],
            [$subset->numGlyphs, $subset->componentGlyphs(1), $subset->glyphData(2)],
        );
    }

    #[Test]
    public function aFontWithoutAPostTableGetsAnEmptyFormat3Post(): void
    {
        $font   = OpenTypeFont::fromData(SfntTables::replace(self::dejaVuData(), ['post' => null]));
        $subset = OpenTypeFont::fromData(TrueTypeSubsetter::subset($font, [0, 36]));

        self::assertSame("\0\x03\0\0" . str_repeat("\0", times: 28), $subset->table('post'));
    }

    #[Test]
    public function aGlyphAskedForTwiceKeepsItsFirstPlace(): void
    {
        $font = self::dejaVu();

        self::assertSame(
            TrueTypeSubsetter::subset($font, [0, 36, 68]),
            TrueTypeSubsetter::subset($font, [0, 36, 36, 68, 0]),
        );
    }

    #[Test]
    public function anIdTheFontLacksKeepsItsPlaceAsAnEmptyGlyph(): void
    {
        $font   = self::dejaVu();
        $subset = OpenTypeFont::fromData(TrueTypeSubsetter::subset($font, [0, -3, 36]));

        self::assertSame(['', $font->glyphData(36)], [$subset->glyphData(1), $subset->glyphData(2)]);
    }

    #[Test]
    public function glyphsAreNumberedInTheOrderGiven(): void
    {
        $font   = self::dejaVu();
        $subset = OpenTypeFont::fromData(TrueTypeSubsetter::subset($font, [0, 68, 36]));

        self::assertSame(
            [3, $font->glyphData(0), $font->glyphData(68), $font->glyphData(36)],
            [$subset->numGlyphs, $subset->glyphData(0), $subset->glyphData(1), $subset->glyphData(2)],
        );
    }

    #[Test]
    #[DataProvider('horizontalMetricsProvider')]
    public function theSubsetHmtxHoldsEveryKeptGlyphsAdvanceAndBearing(?int $longMetrics, ?string $hmtx): void
    {
        $changes = [];
        if (null !== $longMetrics) {
            $hhea            = (string) self::dejaVu()->table('hhea');
            $changes['hhea'] = substr_replace($hhea, pack('n', $longMetrics), offset: 34, length: 2);
        }
        if (null !== $hmtx) {
            $changes['hmtx'] = $hmtx;
        }
        $font = OpenTypeFont::fromData(SfntTables::replace(self::dejaVuData(), $changes));

        $expected = '';
        foreach ([0, 3000, 36] as $gid) {
            $expected .= pack('nn', $font->advance($gid), $font->leftSideBearing($gid) & 0xFFFF);
        }
        $subset = OpenTypeFont::fromData(TrueTypeSubsetter::subset($font, [0, 3000, 36]));

        self::assertSame($expected, $subset->table('hmtx'));
    }

    #[Test]
    public function theSubsetsChecksumAdjustmentBalancesTheWholeFont(): void
    {
        $data = TrueTypeSubsetter::subset(self::dejaVu(), [0, 36, 68, 3000]);
        $data .= str_repeat("\0", (4 - (strlen($data) % 4)) % 4);
        $sum  = 0;
        foreach (unpack('N*', $data) as $word) {
            $sum = ($sum + (int) $word) & 0xFFFF_FFFF;
        }

        self::assertSame(0xB1B0_AFBA, $sum);
    }
}
