<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font\OpenType;

use Php2Pdf\Font\OpenType\Gpos;
use Php2Pdf\Font\OpenType\GposAdjustment;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function file_get_contents;
use function implode;
use function mb_ord;
use function mb_str_split;
use function mt_rand;
use function mt_srand;

/**
 * Pair adjustment lookups remembered by glyph pair position text as the general path does.
 */
#[Group('integration')]
final class GposPairMemoTest extends TestCase
{
    private const array FEATURES = ['kern', 'mark', 'mkmk', 'curs', 'dist', 'abvm', 'blwm'];

    /** Letters, digits, punctuation, ligature starts and combining marks a lookup may pass over. */
    private const array ALPHABET = [
        'A',
        'V',
        'W',
        'Y',
        'T',
        'L',
        'P',
        'F',
        'o',
        'a',
        'e',
        'y',
        'v',
        'w',
        'r',
        'f',
        'i',
        'l',
        't',
        'k',
        'x',
        'j',
        '.',
        ',',
        '\'',
        '"',
        '-',
        '(',
        ')',
        '1',
        '7',
        '4',
        ' ',
        'é',
        'Å',
        'ı',
        "\u{0301}",
        "\u{0308}",
        "\u{0327}",
        "\u{030A}",
    ];

    /** @return array<string, array{0: string, 1: int}> a font, and the seed of its random words */
    public static function fontProvider(): array
    {
        $cases = [];
        foreach ([
            'DejaVu Sans'    => 'fonts/DejaVuSans.ttf',
            'Source Serif 4' => 'fonts/SourceSerif4-Regular.ttf',
            'Source Sans 3'  => 'fonts/SourceSans3-Regular.otf',
        ] as $name => $font) {
            foreach ([1, 2, 3] as $seed) {
                $cases["{$name}, words {$seed}"] = [$font, $seed];
            }
        }

        return $cases;
    }

    /** The font's GPOS, which it has, its pair memos keeping at most $pairMemoLimit pairs (0: none). */
    private static function gpos(OpenTypeFont $program, int $pairMemoLimit): Gpos
    {
        $reader = $program->tableReader('GPOS');
        self::assertNotNull($reader);

        return new Gpos($reader, $program->gdef(), $pairMemoLimit);
    }

    /**
     * Each text's positions from a GPOS, every glyph listed.
     *
     * @param list<string> $texts
     *
     * @return list<list<array{0: int, 1: int, 2: int, 3: int}>>
     */
    private static function positions(OpenTypeFont $program, Gpos $gpos, array $texts): array
    {
        $out = [];
        foreach ($texts as $text) {
            $sequence = [];
            foreach (mb_str_split($text) as $char) {
                $codePoint  = (int) mb_ord($char);
                $sequence[] = [$program->glyphId($codePoint), [$codePoint]];
            }
            $sequence = $program->gsub()?->apply($sequence, ['ccmp', 'liga'], 'latn') ?? $sequence;
            $advances = [];
            foreach ($sequence as [$id]) {
                $advances[] = $program->advance($id);
            }
            $out[] = self::whole($gpos->position($sequence, $advances, self::FEATURES), $advances);
        }

        return $out;
    }

    /**
     * Every glyph's position: a glyph not listed keeps its advance. Pairs remembered do not list a
     * second glyph they leave as it is, where the general path lists it unchanged.
     *
     * @param array<int, array{0: int, 1: int, 2: int, 3: int}> $positions
     * @param list<int>                                         $advances
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    private static function whole(array $positions, array $advances): array
    {
        $whole = [];
        foreach ($advances as $k => $advance) {
            $whole[] = $positions[$k] ?? [$advance, 0, 0, 0];
        }

        return $whole;
    }

    /** @return list<string> */
    private static function words(int $seed): array
    {
        mt_srand($seed);
        $words = [];
        for ($w = 0; $w < 400; ++$w) {
            $word = '';
            for (
                $k = 0,
                    $length = mt_rand(
                        min: 1,
                        max: 9,
                    );
                $k < $length;
                ++$k
            ) {
                $word .=
                    self::ALPHABET[mt_rand(
                        min: 0,
                        max: count(self::ALPHABET) - 1,
                    )] ?? '';
            }
            $words[] = $word;
        }

        return $words;
    }

    /** A memo of two pairs starts over at almost every pair and positions as a memo that keeps them all. */
    #[Test]
    public function aFullPairMemoStartsOverAndPositionsAsBefore(): void
    {
        $program = OpenTypeFont::fromData((string) file_get_contents(PdfTools::assetPath('fonts/DejaVuSans.ttf')));
        $texts   = ['AVATAR To WAVE Ty', ...self::words(7)];

        self::assertSame(
            self::positions($program, self::gpos($program, GposAdjustment::PAIR_MEMO_LIMIT), $texts),
            self::positions($program, self::gpos($program, 2), $texts),
        );
    }

    #[Test]
    #[DataProvider('fontProvider')]
    public function rememberedPairsPositionAsTheGeneralPathDoes(string $font, int $seed): void
    {
        $program = OpenTypeFont::fromData((string) file_get_contents(PdfTools::assetPath($font)));
        $words   = self::words($seed);
        $texts   = [implode(' ', $words), ...$words];

        self::assertSame(
            self::positions($program, self::gpos($program, 0), $texts),
            self::positions($program, self::gpos($program, GposAdjustment::PAIR_MEMO_LIMIT), $texts),
        );
    }
}
