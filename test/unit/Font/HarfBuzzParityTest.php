<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Font\GlyphCheck;
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

    #[Test]
    #[DataProvider('textProvider')]
    public function shapingMatchesHarfBuzz(string $font, string $text, string $expected): void
    {
        $doc  = new Document(new MemoryOutput(), new DocumentOptions(glyphCheck: GlyphCheck::Ignore));
        $face = $doc->fonts()->load(PdfTools::assetPath("fonts/{$font}"));
        $upem = $face->program()->unitsPerEm;
        $out  = [];
        foreach ($face->shape($text)->glyphs as $glyph) {
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

        self::assertSame($expected, '[' . implode('|', $out) . ']');
    }
}
