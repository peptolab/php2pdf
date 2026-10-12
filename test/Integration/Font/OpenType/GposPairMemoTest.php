<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font\OpenType;

use Php2Pdf\Font\OpenType\Gpos;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

use function array_fill;
use function array_filter;
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
        'A', 'V', 'W', 'Y', 'T', 'L', 'P', 'F', 'o', 'a', 'e', 'y', 'v', 'w', 'r', 'f', 'i', 'l', 't',
        'k', 'x', 'j', '.', ',', '\'', '"', '-', '(', ')', '1', '7', '4', ' ', 'é', 'Å', 'ı',
        "\u{0301}", "\u{0308}", "\u{0327}", "\u{030A}",
    ];

    /** @return array<string, array{0: string}> */
    public static function fontProvider(): array
    {
        return [
            'DejaVu Sans'     => ['fonts/DejaVuSans.ttf'],
            'Source Serif 4'  => ['fonts/SourceSerif4-Regular.ttf'],
            'Source Sans 3'   => ['fonts/SourceSans3-Regular.otf'],
        ];
    }

    /** The font's GPOS, which it has. */
    private static function gpos(OpenTypeFont $program): Gpos
    {
        $gpos = $program->gpos();
        self::assertNotNull($gpos);

        return $gpos;
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
            for ($k = 0, $length = mt_rand(1, 9); $k < $length; ++$k) {
                $word .= self::ALPHABET[mt_rand(0, \count(self::ALPHABET) - 1)];
            }
            $words[] = $word;
        }

        return $words;
    }

    #[Test]
    #[DataProvider('fontProvider')]
    public function rememberedPairsPositionAsTheGeneralPathDoes(string $font): void
    {
        $data    = (string) file_get_contents(PdfTools::assetPath($font));
        $memo    = OpenTypeFont::fromData($data);
        $general = OpenTypeFont::fromData($data);
        // No lookup is taken as pairs only: every one applies glyph by glyph.
        (new ReflectionProperty(Gpos::class, 'pairMemo'))->setValue(self::gpos($general), array_fill(0, 4096, null));
        foreach ([1, 2, 3] as $seed) {
            $words = self::words($seed);
            foreach ([implode(' ', $words), ...$words] as $text) {
                $sequence = [];
                foreach (mb_str_split($text) as $char) {
                    $codePoint  = (int) mb_ord($char);
                    $sequence[] = [$memo->glyphId($codePoint), [$codePoint]];
                }
                $sequence = $memo->gsub()?->apply($sequence, ['ccmp', 'liga'], 'latn') ?? $sequence;
                $advances = [];
                foreach ($sequence as [$id]) {
                    $advances[] = $memo->advance($id);
                }
                self::assertSame(
                    self::whole(self::gpos($general)->position($sequence, $advances, self::FEATURES), $advances),
                    self::whole(self::gpos($memo)->position($sequence, $advances, self::FEATURES), $advances),
                    $text,
                );
            }
        }
        // The fonts kern with lookups of pairs only, so the remembered path is the one tested.
        $remembered = (new ReflectionProperty(Gpos::class, 'pairMemo'))->getValue(self::gpos($memo));
        self::assertNotSame([], array_filter($remembered, static fn(?array $pairs): bool => [] !== ($pairs ?? [])));
    }

    #[Test]
    public function aFullPairMemoStartsAgainAndPositionsAsBefore(): void
    {
        $program  = OpenTypeFont::fromData((string) file_get_contents(PdfTools::assetPath('fonts/DejaVuSans.ttf')));
        $gpos     = self::gpos($program);
        $sequence = [];
        foreach (mb_str_split('AVATAR To WAVE Ty') as $char) {
            $codePoint  = (int) mb_ord($char);
            $sequence[] = [$program->glyphId($codePoint), [$codePoint]];
        }
        $advances = [];
        foreach ($sequence as [$id]) {
            $advances[] = $program->advance($id);
        }
        $expected = $gpos->position($sequence, $advances, self::FEATURES);
        $property = new ReflectionProperty(Gpos::class, 'pairMemo');
        /** @var array<int, array<int, mixed>|null> $memo */
        $memo   = $property->getValue($gpos);
        $filled = 0;
        foreach ($memo as $lookup => $pairs) {
            if (null !== $pairs) {
                // Pairs of a first glyph the font does not have, so none is looked up.
                $memo[$lookup] = array_fill(1 << 30, 32768, false);
                ++$filled;
            }
        }
        self::assertGreaterThan(0, $filled);
        $property->setValue($gpos, $memo);
        self::assertSame($expected, $gpos->position($sequence, $advances, self::FEATURES));
        foreach ($property->getValue($gpos) as $pairs) {
            self::assertLessThan(32768, \count($pairs ?? []));
        }
    }
}
