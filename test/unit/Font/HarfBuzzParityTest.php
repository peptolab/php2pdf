<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Font\GlyphCheck;
use Php2Pdf\Font\TextDirection;
use Php2Pdf\Font\TextOptions;
use Php2PdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function implode;
use function round;

/**
 * Shaping matches HarfBuzz 11 (hb-shape with its default features, glyph ids, offsets and
 * advances in font units) for marks, normalisation, scripts and kerning (#33).
 */
#[Group('unit')]
final class HarfBuzzParityTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function rightToLeftProvider(): array
    {
        return [
            'Hebrew: marks reordered'         => [
                'DejaVuSans.ttf',
                'שָׁלוֹם',
                '[1332+1359|1306+0|1324+558|1331+1164|1305+0|1314+0|1344+1451]',
            ],
            'Hebrew: marks on marks'          => [
                'DejaVuSans.ttf',
                'וַיֹּאמֶר אֱלֹהִים',
                '[1332+1359|1328+458|1301@28,0+0|1323+1338|1306+0|1331+1164|1298@79,0+0|1319+1369|3+651|1343+1156|1303@111,0+0|1333+1391|1319+1369|1306+0|1309@-659,235+0|1328+458|1304@-384,0+0|1324+558]',
            ],
            'Hebrew: mirrored brackets'       => [
                'DejaVuSans.ttf',
                '(א) [ב]',
                '[62+799|1320+1184|64+799|3+651|11+799|1319+1369|12+799]',
            ],
            'Arabic: joining'                 => [
                'DejaVuSans.ttf',
                'مرحبا بالعالم',
                '[5340+1363|5337+624|5256+624|5318+988|5337+624|5256+624|5259+570|3+651|5256+624|5260+618|5277+1266|5288+1130|5341+1097]',
            ],
            'Arabic: lam-alef'                => [
                'DejaVuSans.ttf',
                'لا إله إلا الله',
                '[5348+1098|5338+678|5337+624|1365+569|3+651|5365+1168|1363+569|3+651|5348+1098|5337+624|1363+569|3+651|5365+1168]',
            ],
            'Arabic: vowel marks'             => [
                'DejaVuSans.ttf',
                'سَلَامٌ عَلَيْكُمْ',
                '[1403@138,-350+0|5340+1363|1400@-262,350+0|5334+1131|1403@-213,-200+0|5358+618|1399@-173,450+0|5338+678|1399@-62,-50+0|5317+1222|3+651|1397@138,-250+0|1390+1268|1399@355,450+0|5366+1222|1399@138,-300+0|5293+1716]',
            ],
            'Persian: a non-joiner'           => [
                'DejaVuSans.ttf',
                "پیام\u{200C}رسان",
                '[1391+1504|5256+624|5293+1716|1375+989|3+0|1390+1268|5256+624|5206+618|5105+570]',
            ],
            'Arabic: a modifier mark first'   => [
                'DejaVuSans.ttf',
                "ب\u{064C}\u{0654}",
                '[1397@388,-200+0|1405@388,-200+0|1366+1928]',
            ],
            'cursive attachment with marks'   => [
                'PhpPdfCursiveTest.ttf',
                "س\u{064E}با",
                '[3+120|2@-650,-150+-650|7@-290,-500+0|4@-760,-450+40]',
            ],
            'cursive attachment'              => [
                'PhpPdfCursiveTest.ttf',
                'بسب',
                '[2+0|4@-760,-300+-730|2@-650,-600+50]',
            ],
            'presentation forms and lam-alef' => [
                'PhpPdfArabicFallbackTest.ttf',
                'بلا لا',
                '[15+855|1+250|16+892|7+559]',
            ],
        ];
    }

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function textProvider(): array
    {
        return [
            'DejaVu: marks'             => [
                'DejaVuSans.ttf',
                "f\u{0301}i q\u{0323}\u{0302} o\u{0328}\u{0304}",
                '[73+721|5923@271,373+0|76+569|3+651|84+1300|724@-140,-429+0|691@-165,0+0|3+651|431+1253]',
            ],
            'DejaVu: composition'       => [
                'DejaVuSans.ttf',
                "Ta\u{0301} n\u{0303} \u{212B}",
                '[55+912|163+1255|3+651|179+1298|3+651|3011+1401]',
            ],
            'DejaVu: Greek'             => [
                'DejaVuSans.ttf',
                'Ελληνικά',
                '[811+1294|848+1212|848+1212|844+1298|850+1144|846+693|847+1207|833+1350]',
            ],
            'DejaVu: kerning'           => [
                'DejaVuSans.ttf',
                'AVATo office',
                '[36+1270|57+1270|36+1242|55+903|82+1253|3+651|82+1253|5044+1980|70+1126|72+1260]',
            ],
            'Source Serif: marks'       => [
                'SourceSerif4-Regular.ttf',
                "f\u{0301}i q\u{0323}\u{0302} o\u{0328}\u{0304}",
                '[33+384|784@-80,50+0|36+298|1+233|44+557|809@-127,-240+0|785@-256,0+0|1+233|42+549|789@-274,0+0|805@-198,0+0]',
            ],
            'Source Serif: composition' => [
                'SourceSerif4-Regular.ttf',
                "Ta\u{0301} n\u{0303} \u{212B}",
                '[21+563|232+509|1+233|321+606|1+233|74+664]',
            ],
            'Source Serif: Greek'       => [
                'SourceSerif4-Regular.ttf',
                'Ελληνικά',
                '[842+603|881+538|881+533|877+550|883+491|879+287|880+510|896+553]',
            ],
            'Source Serif: kerning'     => [
                'SourceSerif4-Regular.ttf',
                'AVATo office',
                '[2+545|23+554|2+603|21+534|42+549|1+233|42+549|422+911|30+488|32+510]',
            ],
            'Source Sans: marks'        => [
                'SourceSans3-Regular.otf',
                "f\u{0301}i q\u{0323}\u{0302} o\u{0328}\u{0304}",
                '[33+292|2304@-74,50+0|36+246|1+200|44+555|2356@-131,-185+0|2306@-277,0+0|1+200|470+542|2310@-271,0+0]',
            ],
            'Source Sans: Greek'        => [
                'SourceSans3-Regular.otf',
                'Ελληνικά',
                '[799+527|839+447|839+494|835+541|841+474|837+262|838+476|863+560]',
            ],
            'Source Sans: kerning'      => [
                'SourceSans3-Regular.otf',
                'AVATo office',
                '[2+530|23+501|2+489|21+470|42+542|1+200|42+542|687+577|36+246|30+435|32+496]',
            ],
        ];
    }

    /** The shaped glyphs as hb-shape prints them: id, offsets and advance in font units. */
    private static function shaped(string $font, string $text, TextOptions $options): string
    {
        $doc  = new Document(new MemoryOutput(), new DocumentOptions(glyphCheck: GlyphCheck::Ignore));
        $face = $doc->fonts()->load(PdfTools::assetPath("fonts/{$font}"));
        $upem = $face->program()->unitsPerEm;
        $out  = [];
        foreach ($face->shape($text, $options)->glyphs as $glyph) {
            $natural = $face->program()->advance($glyph->id);
            $drawn   = (int) round((($glyph->advance + $glyph->kerning) * $upem) / 1000);
            $advance = match (true) {
                0.0 === $glyph->kerning => $natural,
                0 === $drawn => 0,
                default => $natural + (int) round(($glyph->kerning * $upem) / 1000),
            };
            $x     = (int) round(($glyph->xOffset * $upem) / 1000);
            $y     = (int) round(($glyph->yOffset * $upem) / 1000);
            $out[] = $glyph->id . (0 !== $x || 0 !== $y ? "@{$x},{$y}" : '') . "+{$advance}";
        }

        return '[' . implode('|', $out) . ']';
    }

    #[Test]
    public function leftToRightCursiveAttachmentMatchesHarfBuzz(): void
    {
        self::assertSame(
            '[5+480|6@-40,60+480|5@-20,20+460|6@-40,80+510|1+250|6+520|5@-20,-40+480]',
            self::shaped('PhpPdfCursiveTest.ttf', 'abab ba', new TextOptions()),
        );
    }

    #[Test]
    #[DataProvider('rightToLeftProvider')]
    public function rightToLeftShapingMatchesHarfBuzz(string $font, string $text, string $expected): void
    {
        self::assertSame($expected, self::shaped($font, $text, new TextOptions(direction: TextDirection::RightToLeft)));
    }

    #[Test]
    #[DataProvider('textProvider')]
    public function shapingMatchesHarfBuzz(string $font, string $text, string $expected): void
    {
        self::assertSame($expected, self::shaped($font, $text, new TextOptions()));
    }
}
