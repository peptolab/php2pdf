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
    public function aFontWithoutAPostTableGetsAnEmptyFormat3Post(): void
    {
        $font   = OpenTypeFont::fromData(SfntTables::replace(self::dejaVuData(), ['post' => null]));
        $subset = OpenTypeFont::fromData(TrueTypeSubsetter::subset($font, [36 => true]));

        self::assertSame("\0\x03\0\0" . str_repeat("\0", times: 28), $subset->table('post'));
    }

    #[Test]
    public function negativeGlyphIdsAreIgnored(): void
    {
        $font = self::dejaVu();

        self::assertSame(
            TrueTypeSubsetter::subset($font, [36 => true]),
            TrueTypeSubsetter::subset($font, [-3 => true, -1 => true, 36 => true]),
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
        for ($gid = 0; $gid <= 3000; ++$gid) {
            $expected .= pack('nn', $font->advance($gid), $font->leftSideBearing($gid) & 0xFFFF);
        }
        $subset = OpenTypeFont::fromData(TrueTypeSubsetter::subset($font, [36 => true, 3000 => true]));

        self::assertSame($expected, $subset->table('hmtx'));
        self::assertSame(3001, $subset->numGlyphs);
    }

    #[Test]
    public function theSubsetsChecksumAdjustmentBalancesTheWholeFont(): void
    {
        $data = TrueTypeSubsetter::subset(self::dejaVu(), [36 => true, 68 => true, 3000 => true]);
        $data .= str_repeat("\0", (4 - (strlen($data) % 4)) % 4);
        $sum  = 0;
        foreach (unpack('N*', $data) as $word) {
            $sum = ($sum + (int) $word) & 0xFFFF_FFFF;
        }

        self::assertSame(0xB1B0_AFBA, $sum);
    }

    #[Test]
    public function unusedGlyphsBecomeEmptyAndKeptGlyphsKeepTheirOutlines(): void
    {
        $font   = self::dejaVu();
        $subset = OpenTypeFont::fromData(TrueTypeSubsetter::subset($font, [36 => true, 68 => true]));

        self::assertSame(69, $subset->numGlyphs);
        self::assertSame($font->glyphData(0), $subset->glyphData(0));
        self::assertSame($font->glyphData(36), $subset->glyphData(36));
        self::assertSame($font->glyphData(68), $subset->glyphData(68));
        self::assertSame('', $subset->glyphData(1));
        self::assertSame('', $subset->glyphData(67));
    }
}
