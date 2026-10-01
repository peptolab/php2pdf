<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\OpenType;

use Php2Pdf\Font\OpenType\BinaryReader;
use Php2Pdf\Font\OpenType\Gsub;
use Php2PdfTest\Support\GsubBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;

/**
 * Glyph substitution from GSUB: standard ligatures (#5) and other features (#32). Glyphs: f 1,
 * i 2, l 3, o 4, digits 5 to 8 (one to four), slash 9.
 */
#[Group('unit')]
final class GsubTest extends TestCase
{
    /** ffi 10, ff 11, fl 12, fi 13 */
    private const array LIGATURES = [1 => [[[1, 2], 10], [[1], 11], [[3], 12], [[2], 13]]];

    /** @return array<string, array{0: bool}> */
    public static function extensionProvider(): array
    {
        return ['a plain lookup' => [false], 'an extension lookup' => [true]];
    }

    /** @return array<string, array{0: list<array{0: int, 1: list<string>, 2?: bool}>, 1: list<int>}> */
    public static function substitutionProvider(): array
    {
        $listed        = [GsubBuilder::single2([5 => 55, 8 => 88])];
        $toNumerator   = [GsubBuilder::single1([5, 6, 7, 8], 200)];
        $toDenominator = [GsubBuilder::single1([5, 6, 7, 8], 300)];

        return [
            'single, delta'                              => [
                [[1, [GsubBuilder::single1([5, 6, 7, 8], 100)]]],
                [105, 9, 108],
            ],
            'single, glyph list'                         => [[[1, $listed]], [55, 9, 88]],
            'alternate, the first'                       => [
                [[3, [GsubBuilder::alternate([5 => [50, 51], 8 => [80]])]]],
                [50, 9, 80],
            ],
            'chained, by coverage: digit before a slash' => [
                [[6, [GsubBuilder::chain3([], [[5, 6, 7, 8]], [[9]], [[0, 1]])]], [1, $toNumerator]],
                [205, 9, 8],
            ],
            'chained, by coverage: digit after a slash'  => [
                [[6, [GsubBuilder::chain3([[9]], [[5, 6, 7, 8]], [], [[0, 1]])]], [1, $toDenominator]],
                [5, 9, 308],
            ],
            'chained, by glyph'                          => [
                [
                    [6, [GsubBuilder::chain1([5 => [[[], [9, 8], [], [[0, 1], [2, 2]]]]])]],
                    [1, $toNumerator],
                    [1, $toDenominator],
                ],
                [205, 9, 308],
            ],
            'chained, by class'                          => [
                [
                    [
                        6,
                        [GsubBuilder::chain2(
                            [5, 6, 7, 8],
                            [],
                            [5 => 1, 6 => 1, 7 => 1, 8 => 1, 9 => 2],
                            [],
                            [1 => [[[], [2, 1], [], [[0, 1], [2, 2]]]]],
                        )],
                    ],
                    [1, $toNumerator],
                    [1, $toDenominator],
                ],
                [205, 9, 308],
            ],
        ];
    }

    /**
     * @param array<int, int> $ids
     *
     * @return list<array{0: int, 1: list<int>}>
     */
    private static function glyphs(array $ids): array
    {
        $codePoints = [
            1 => 0x66,
            2 => 0x69,
            3 => 0x6C,
            4 => 0x6F,
            5 => 0x31,
            6 => 0x32,
            7 => 0x33,
            8 => 0x34,
            9 => 0x2F,
        ];

        return array_map(static fn(int $id): array => [$id, [$codePoints[$id] ?? 0]], $ids);
    }

    /**
     * @param array<string, list<int>>                       $features
     * @param list<array{0: int, 1: list<string>, 2?: bool}> $lookups
     */
    private static function gsub(array $features, array $lookups, string $script = 'latn'): Gsub
    {
        return new Gsub(new BinaryReader(GsubBuilder::build($script, $features, $lookups)));
    }

    /**
     * @param list<array{0: int, 1: list<int>}> $glyphs
     *
     * @return list<int>
     */
    private static function ids(array $glyphs): array
    {
        return array_column($glyphs, 0);
    }

    #[Test]
    public function aLigatureStandsForAllItsCharacters(): void
    {
        $gsub = self::gsub(['liga' => [0]], [[4, [GsubBuilder::ligature(self::LIGATURES)]]]);

        self::assertSame(
            [[0x6F], [0x66, 0x66, 0x69]],
            array_column($gsub->apply(self::glyphs([4, 1, 1, 2]), ['liga']), 1),
        );
    }

    #[Test]
    public function lookupsApplyInLookupOrder(): void
    {
        $gsub = self::gsub(['liga' => [0, 1]], [
            [4, [GsubBuilder::ligature([1 => [[[1], 11]]])]],
            [4, [GsubBuilder::ligature([11 => [[[2], 20]]])]],
        ]);

        self::assertSame([20], self::ids($gsub->apply(self::glyphs([1, 1, 2]), ['liga'])));
    }

    #[Test]
    public function onlyTheRequestedFeaturesApply(): void
    {
        $gsub = self::gsub(['liga' => [0], 'onum' => [1]], [
            [4, [GsubBuilder::ligature(self::LIGATURES)]],
            [1, [GsubBuilder::single1([5, 6, 7, 8], 100)]],
        ]);

        self::assertSame(
            [[11, 5], [1, 1, 105], [11, 105]],
            [
                self::ids($gsub->apply(self::glyphs([1, 1, 5]), ['liga'])),
                self::ids($gsub->apply(self::glyphs([1, 1, 5]), ['onum'])),
                self::ids($gsub->apply(self::glyphs([1, 1, 5]), ['onum', 'liga'])),
            ],
        );
    }

    #[Test]
    public function otherScriptsAreNotApplied(): void
    {
        self::assertFalse(self::gsub(['liga' => [0]], [[4, [GsubBuilder::ligature(self::LIGATURES)]]], 'cyrl')->has([
            'liga',
        ]));
    }

    /**
     * "1/4", substituted by the feature's first lookup (which may call the others).
     *
     * @param list<array{0: int, 1: list<string>, 2?: bool}> $lookups
     * @param list<int>                                      $expected
     */
    #[Test]
    #[DataProvider('substitutionProvider')]
    public function substitutes(array $lookups, array $expected): void
    {
        $gsub = self::gsub(['test' => [0]], $lookups);

        self::assertSame($expected, self::ids($gsub->apply(self::glyphs([5, 9, 8]), ['test'])));
    }

    #[Test]
    #[DataProvider('extensionProvider')]
    public function substitutesTheLongestLigatureFirst(bool $extension): void
    {
        $gsub = self::gsub(['liga' => [0]], [[4, [GsubBuilder::ligature(self::LIGATURES)], $extension]]);

        self::assertSame([4, 10, 12, 1], self::ids($gsub->apply(self::glyphs([4, 1, 1, 2, 1, 3, 1]), ['liga'])));
    }

    #[Test]
    public function theDefaultScriptServesFontsWithoutLatin(): void
    {
        self::assertTrue(self::gsub(['liga' => [0]], [[4, [GsubBuilder::ligature(self::LIGATURES)]]], 'DFLT')->has([
            'liga',
        ]));
    }
}
