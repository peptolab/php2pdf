<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\OpenType;

use Php2Pdf\Font\OpenType\BinaryReader;
use Php2Pdf\Font\OpenType\Gdef;
use Php2Pdf\Font\OpenType\Gpos;
use Php2PdfTest\TestAsset\GposBuilder;
use Php2PdfTest\TestAsset\GsubBuilder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_slice;
use function is_int;

/**
 * Glyph positioning from GPOS (#33). Glyphs 1 to 9 are bases with an advance of 500, 10 is a
 * ligature with an advance of 900, and 20 to 22 are marks whose advance of 100 they lose.
 * Positions are x advance, y advance, x offset and y offset in font units.
 */
#[Group('unit')]
final class GposTest extends TestCase
{
    private const array CLASSES = [10 => 2, 20 => 3, 21 => 3, 22 => 3];

    /**
     * @param list<array{0: int, 1: list<string>, 2?: bool, 3?: int, 4?: int}> $lookups
     * @param list<int|array{0: int, 1: list<int>, 2?: int, 3?: int}>       $glyphs
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int}>
     */
    private static function position(array $lookups, array $glyphs, string $script = 'latn'): array
    {
        $gpos = new Gpos(
            new BinaryReader(GsubBuilder::build('latn', ['test' => array_keys($lookups)], $lookups, extensionType: 9)),
            new Gdef(new BinaryReader(GsubBuilder::gdef(self::CLASSES))),
        );
        $entries  = [];
        $advances = [];
        foreach ($glyphs as $glyph) {
            $entry      = is_int($glyph) ? [$glyph, [0x61]] : $glyph;
            $entries[]  = $entry;
            $advances[] = match (true) {
                $entry[0] >= 20 => 100,
                10 === $entry[0] => 900,
                default => 500,
            };
        }

        return $gpos->position($entries, $advances, ['test'], $script);
    }

    #[Test]
    public function aFeatureOnlyAppliesInItsScript(): void
    {
        $gpos = new Gpos(
            new BinaryReader(GsubBuilder::build(
                'grek',
                ['kern' => [0]],
                [[1, [GposBuilder::single1([1], [0, 0, 100])]]],
                extensionType: 9,
            )),
            new Gdef(null),
        );

        self::assertSame([false, true], [$gpos->has(['kern'], 'cyrl'), $gpos->has(['kern'], 'grek')]);
    }

    #[Test]
    public function aMarkAfterALigatureTakesItsLastComponent(): void
    {
        $lookups = [[5, [GposBuilder::markToLigature([20 => [0, [0, 0]]], [10 => [[[200, 600]], [[700, 600]]]])]]];

        self::assertSame([0, 0, -200, 600], self::position($lookups, [10, 20])[1]);
    }

    #[Test]
    public function aMarkOnALigatureUsesTheComponentItFollowed(): void
    {
        $lookups = [[5, [GposBuilder::markToLigature([20 => [0, [0, 0]]], [10 => [[[200, 600]], [[700, 600]]]])]]];

        self::assertSame(
            [[0, 0, -200, 600], [0, 0, -700, 600]],
            [
                self::position($lookups, [[10, [0x66, 0x69], 1, 0], [20, [0x301], 1, 2]])[1],
                self::position($lookups, [[10, [0x66, 0x69], 1, 0], [20, [0x301], 1, 1]])[1],
            ],
        );
    }

    #[Test]
    public function aMarkSitsOnItsBaseAnchor(): void
    {
        $marks = [[4, [GposBuilder::markToBase([20 => [0, [50, 0]]], [1 => [[250, 700]]])]]];

        self::assertSame([[500, 0, 0, 0], [0, 0, -300, 700]], self::position($marks, [1, 20]));
    }

    #[Test]
    public function aMarkStacksOnTheMarkBeforeIt(): void
    {
        $lookups = [
            [4, [GposBuilder::markToBase([20 => [0, [50, 0]], 21 => [0, [50, 0]]], [1 => [[250, 700]]])]],
            [6, [GposBuilder::markToMark([21 => [0, [50, 0]]], [20 => [[50, 300]]])]],
        ];

        self::assertSame(
            [[0, 0, -300, 700], [0, 0, -300, 1000]],
            array_slice(self::position($lookups, [1, 20, 21]), offset: 1),
        );
    }

    #[Test]
    public function aPairPassesOverMarksItIgnores(): void
    {
        $kern = [[2, [GposBuilder::pair1([1 => [2 => [[0, 0, -80], [0, 0, 0]]]])], false, 0x0008]];

        self::assertSame([420, 0, 0, 0], self::position($kern, [1, 20, 2])[0]);
    }

    #[Test]
    public function aPairWithSecondValuesSkipsItsSecondGlyph(): void
    {
        $kern = [[2, [GposBuilder::pair1([1 => [1 => [[0, 0, -10], [0, 0, -20]]]], format2: GposBuilder::VALUES)]]];

        self::assertSame(
            [[490, 0, 0, 0], [480, 0, 0, 0], [490, 0, 0, 0], [480, 0, 0, 0]],
            self::position($kern, [1, 1, 1, 1]),
        );
    }

    #[Test]
    public function contextPositioningAppliesNestedLookups(): void
    {
        $lookups = [
            [8, [GsubBuilder::chain3([], [[1]], [[2]], [[0, 1]])]],
            [1, [GposBuilder::single1([1], [0, 0, 100])]],
        ];
        $gpos = new Gpos(
            new BinaryReader(GsubBuilder::build('latn', ['test' => [0]], $lookups, extensionType: 9)),
            new Gdef(new BinaryReader(GsubBuilder::gdef(self::CLASSES))),
        );

        self::assertSame(
            [[600, 0, 0, 0], [500, 0, 0, 0], [500, 0, 0, 0]],
            $gpos->position([[1, [0x61]], [2, [0x62]], [1, [0x61]]], [500, 500, 500], ['test']),
        );
    }

    #[Test]
    public function marksLoseTheirAdvanceEvenUnpositioned(): void
    {
        self::assertSame(
            [[500, 0, 0, 0], [0, 0, 0, 0]],
            self::position([[1, [GposBuilder::single1([5], [0, 0, 0])]]], [1, 20]),
        );
    }

    #[Test]
    public function marksPassOverEarlierMarksToTheirBase(): void
    {
        $marks = [[
            4,
            [GposBuilder::markToBase([20 => [0, [50, 0]], 21 => [1, [0, 0]]], [1 => [[250, 700], [250, -100]]])],
        ]];

        self::assertSame(
            [[0, 0, -300, 700], [0, 0, -250, -100]],
            array_slice(self::position($marks, [1, 20, 21]), offset: 1),
        );
    }

    #[Test]
    public function pairsAdjustByClass(): void
    {
        $kern = [[2, [GposBuilder::pair2([1, 2], [1 => 1, 2 => 1], [3 => 1], [1 => [1 => [0, 0, -60]]])]]];

        self::assertSame(
            [[440, 0, 0, 0], [440, 0, 0, 0], [500, 0, 0, 0]],
            [self::position($kern, [1, 3])[0], self::position($kern, [2, 3])[0], self::position($kern, [1, 1])[0]],
        );
    }

    #[Test]
    public function pairsAdjustByGlyph(): void
    {
        $kern = [[2, [GposBuilder::pair1([1 => [2 => [[0, 0, -80], [0, 0, 0]]]])]]];

        self::assertSame([[420, 0, 0, 0], [500, 0, 0, 0], [500, 0, 0, 0]], self::position($kern, [1, 2, 1]));
    }

    #[Test]
    public function singleAdjustmentMovesAndWidensGlyphs(): void
    {
        self::assertSame(
            [[520, 0, 10, -5], [500, 0, 0, 0], [530, 0, 0, 0]],
            [
                ...self::position([[1, [GposBuilder::single1([1], [10, -5, 20])]]], [1]),
                ...self::position([[1, [GposBuilder::single2([2 => [0, 0, 10], 3 => [0, 0, 30]])]]], [1, 3]),
            ],
        );
    }
}
