<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\OpenType;

use Php2Pdf\Core\BinaryReader;
use Php2Pdf\Font\OpenType\Gdef;
use Php2Pdf\Font\OpenType\Gsub;
use Php2PdfTest\TestAsset\GsubBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;

/**
 * Glyph substitution from GSUB: standard ligatures (#5), other features (#32), and the lookup
 * types and flags complex scripts use (#33). Glyphs: f 1, i 2, l 3, o 4, digits 5 to 8 (one to
 * four), slash 9; 20 and 21 are marks (20 in attachment class 1 and mark set 0, 21 in class 2).
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

    /** @return array<string, array{0: int, 1: list<int>, 2: list<int>}> */
    public static function flagProvider(): array
    {
        return [
            'no flag: a mark breaks the ligature'          => [0, [1, 20, 2], [1, 20, 2]],
            'ignoring marks'                               => [0x0008, [1, 20, 2], [13, 20]],
            'a mark filtering set keeps its own marks'     => [0x0010, [1, 20, 2], [1, 20, 2]],
            'a mark filtering set passes over other marks' => [0x0010, [1, 21, 2], [13, 21]],
            'a mark attachment class keeps its own marks'  => [0x0100, [1, 20, 2], [1, 20, 2]],
            'a mark attachment class passes over the rest' => [0x0100, [1, 21, 2], [13, 21]],
        ];
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
            'context, by glyph'                          => [
                [[5, [GsubBuilder::context1([5 => [[[9], [[0, 1]]]]])]], [1, $toNumerator]],
                [205, 9, 8],
            ],
            'context, by coverage'                       => [
                [[5, [GsubBuilder::context3([[5, 6, 7, 8], [9]], [[0, 1]])]], [1, $toNumerator]],
                [205, 9, 8],
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
     * @param list<int> $ids
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

        $glyphs = [];
        foreach ($ids as $id) {
            $glyphs[] = [$id, [$codePoints[$id] ?? 0]];
        }

        return $glyphs;
    }

    /**
     * @param array<string, list<int>>                                         $features
     * @param list<array{0: int, 1: list<string>, 2?: bool, 3?: int, 4?: int}> $lookups
     */
    private static function gsub(
        array $features,
        array $lookups,
        string $script = 'latn',
        ?string $required = null,
    ): Gsub {
        return new Gsub(
            new BinaryReader(GsubBuilder::build($script, $features, $lookups, $required)),
            new Gdef(new BinaryReader(GsubBuilder::gdef([20 => 3, 21 => 3], [20 => 1, 21 => 2], [[20]]))),
        );
    }

    /**
     * @param list<array{0: int, 1: list<int>, 2?: int, 3?: int, 4?: string}> $glyphs
     *
     * @return list<int>
     */
    private static function ids(array $glyphs): array
    {
        $ids = [];
        foreach ($glyphs as [$id]) {
            $ids[] = $id;
        }

        return $ids;
    }

    #[Test]
    public function aBacktrackPassesOverWhatALigatureTookEarlierInTheSameLookup(): void
    {
        $gsub = self::gsub(['liga' => [0]], [
            [
                6,
                [
                    GsubBuilder::chain1([5 => [[[], [6], [], [[0, 1]]]]]),
                    GsubBuilder::chain3([[50]], [[9]], [], [[0, 2]]),
                ],
            ],
            [4, [GsubBuilder::ligature([5 => [[[6], 50]]])]],
            [1, [GsubBuilder::single2([9 => 99])]],
        ]);

        self::assertSame([50, 99], self::ids($gsub->apply(self::glyphs([5, 6, 9]), ['liga'])));
    }

    #[Test]
    public function aLigatureFormsAcrossAGlyphDeletedEarlierInTheSameContext(): void
    {
        $gsub = self::gsub(['liga' => [0]], [
            [5, [GsubBuilder::context1([5 => [[[6, 9], [[1, 1], [0, 2]]]]])]],
            [2, [GsubBuilder::multiple([6 => []])]],
            [4, [GsubBuilder::ligature([5 => [[[9], 70]]])]],
        ]);

        self::assertSame([70, 8], self::ids($gsub->apply(self::glyphs([5, 6, 9, 8]), ['liga'])));
    }

    #[Test]
    public function aLigatureInsideAContextLeavesTheLaterRecordsOnTheirGlyphs(): void
    {
        $gsub = self::gsub(['liga' => [0]], [
            [5, [GsubBuilder::context1([5 => [[[6, 9], [[0, 1], [1, 2]]]]])]],
            [4, [GsubBuilder::ligature([5 => [[[6], 50]]])]],
            [1, [GsubBuilder::single2([6 => 66, 9 => 99])]],
        ]);

        self::assertSame([50, 99, 8], self::ids($gsub->apply(self::glyphs([5, 6, 9, 8]), ['liga'])));
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
    public function aMarkInsideALigatureFollowsItWithItsComponent(): void
    {
        $gsub = self::gsub(['liga' => [0]], [[4, [GsubBuilder::ligature([1 => [[[2], 13]]])], false, 0x0008]]);
        $out  = $gsub->apply([[1, [0x66]], [20, [0x301]], [2, [0x69]]], ['liga']);

        self::assertSame([[13, [0x66, 0x69], 1, 0, ''], [20, [0x301], 1, 1, '']], $out);
    }

    #[Test]
    public function aScriptIsChosenByTag(): void
    {
        $gsub = self::gsub(['liga' => [0]], [[4, [GsubBuilder::ligature(self::LIGATURES)]]], 'arab');

        self::assertSame([[1, 2], [13]], [
            self::ids($gsub->apply(self::glyphs([1, 2]), ['liga'], 'cyrl')),
            self::ids($gsub->apply(self::glyphs([1, 2]), ['liga'], 'arab')),
        ]);
    }

    #[Test]
    public function chainedContextPassesOverIgnoredMarks(): void
    {
        $gsub = self::gsub(['test' => [0]], [
            [6, [GsubBuilder::chain3([], [[5, 6, 7, 8]], [[9]], [[0, 1]])], false, 0x0008],
            [1, [GsubBuilder::single1([5, 6, 7, 8], 200)]],
        ]);

        self::assertSame([205, 20, 9, 8], self::ids($gsub->apply(self::glyphs([5, 20, 9, 8]), ['test'])));
    }

    #[Test]
    public function glyphsAMultipleSubstitutionAddsInsideAContextJoinItsInput(): void
    {
        $gsub = self::gsub(['liga' => [0]], [
            [5, [GsubBuilder::context1([5 => [[[9], [[0, 1], [1, 2]]]]])]],
            [2, [GsubBuilder::multiple([5 => [50, 51]])]],
            [1, [GsubBuilder::single2([9 => 99, 51 => 151])]],
        ]);

        self::assertSame([50, 151, 9, 8], self::ids($gsub->apply(self::glyphs([5, 9, 8]), ['liga'])));
    }

    /**
     * @param list<int> $input
     * @param list<int> $expected
     */
    #[Test]
    #[DataProvider('flagProvider')]
    public function lookupFlagsDecideWhichMarksALigaturePassesOver(int $flag, array $input, array $expected): void
    {
        $gsub = self::gsub(['liga' => [0]], [[4, [GsubBuilder::ligature([1 => [[[2], 13]]])], false, $flag, 0]]);

        self::assertSame($expected, self::ids($gsub->apply(self::glyphs($input), ['liga'])));
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
    public function multipleSubstitutionIntoNothingDeletesTheGlyph(): void
    {
        $gsub = self::gsub(['ccmp' => [0]], [[2, [GsubBuilder::multiple([4 => []])]]]);

        self::assertSame([1, 2], self::ids($gsub->apply(self::glyphs([1, 4, 2]), ['ccmp'])));
    }

    #[Test]
    public function multipleSubstitutionKeepsTheTextOnTheFirstGlyph(): void
    {
        $gsub = self::gsub(['ccmp' => [0]], [[2, [GsubBuilder::multiple([4 => [40, 41]])]]]);

        self::assertSame(
            [[1, [0x66]], [40, [0x6F], 0, 0, ''], [41, [], 0, 0, '']],
            $gsub->apply(self::glyphs([1, 4]), ['ccmp']),
        );
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

    #[Test]
    public function reverseChainingRunsFromTheEnd(): void
    {
        $gsub = self::gsub(['rclt' => [0]], [[8, [GsubBuilder::reverse([5 => 55, 6 => 66], [], [[66, 9]])]]]);

        self::assertSame([55, 66, 9], self::ids($gsub->apply(self::glyphs([5, 6, 9]), ['rclt'])));
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

    #[Test]
    public function theRequiredFeatureAlwaysApplies(): void
    {
        $gsub = self::gsub(
            ['rqd ' => [0], 'onum' => [1]],
            [[1, [GsubBuilder::single1([1], 100)]], [1, [GsubBuilder::single1([5, 6, 7, 8], 100)]]],
            required: 'rqd ',
        );

        self::assertSame([101, 5], self::ids($gsub->apply(self::glyphs([1, 5]), [])));
    }
}
