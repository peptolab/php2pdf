<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\OpenType;

use Php2Pdf\Core\BinaryReader;
use Php2Pdf\Font\OpenType\Gdef;
use Php2Pdf\Font\OpenType\GlyphFilter;
use Php2Pdf\Font\OpenType\GlyphPositions;
use Php2Pdf\Font\OpenType\GposAttachment;
use Php2Pdf\Font\OpenType\LayoutTables;
use Php2Pdf\Font\TextDirection;
use Php2PdfTest\TestAsset\Font\LayoutSubtableBuilder;
use Php2PdfTest\TestAsset\GposBuilder;
use Php2PdfTest\TestAsset\GsubBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function array_map;
use function count;

/**
 * GPOS attachment subtables applied directly at a position, each subtable alone at the start of
 * its table. Glyphs 1 to 9 are bases, 10 a ligature and 20 to 22 marks; every glyph starts with
 * an x advance of 500. Positions are x and y advance, x and y offset, the glyph attached to
 * (relative) and how.
 *
 * @phpstan-type Entry array{0: int, 1: list<int>, 2?: int, 3?: int}
 */
#[Group('unit')]
final class GposAttachmentTest extends TestCase
{
    private const array CLASSES = [10 => 2, 20 => 3, 21 => 3, 22 => 3];

    /** @return array<string, array{0: int, 1: string, 2: list<Entry>}> */
    public static function rejectionProvider(): array
    {
        $mark = [20 => [0, [0, 0]]];

        return [
            'mark to base, a mark it does not cover'               => [
                4,
                GposBuilder::markToBase($mark, [1 => [[250, 700]]]),
                [[1, [0x61]], [21, [0x302]]],
            ],
            'mark to base, a base without an anchor for its class' => [
                4,
                GposBuilder::markToBase([20 => [1, [0, 0]]], [1 => [[250, 700]]]),
                [[1, [0x61]], [20, [0x301]]],
            ],
            'mark to ligature, a ligature with no components'      => [
                5,
                GposBuilder::markToLigature($mark, [10 => []]),
                [[10, [0x66, 0x69]], [20, [0x301]]],
            ],
            'mark to ligature, a component without an anchor'      => [
                5,
                GposBuilder::markToLigature($mark, [10 => [[]]]),
                [[10, [0x66, 0x69]], [20, [0x301]]],
            ],
            'mark to mark, a mark without an anchor for its class' => [
                6,
                GposBuilder::markToMark([21 => [0, [0, 0]]], [20 => []]),
                [[20, [0x301]], [21, [0x302]]],
            ],
            'mark to mark, on another ligature component'          => [
                6,
                GposBuilder::markToMark([21 => [0, [50, 0]]], [20 => [[50, 300]]]),
                [[20, [0x301]], [21, [0x302], 1, 1]],
            ],
        ];
    }

    private static function attachment(string $subtable): GposAttachment
    {
        $gdef = self::gdef();

        return new GposAttachment(LayoutTables::gpos(new BinaryReader($subtable), $gdef), $gdef);
    }

    /**
     * Applies cursive attachment at glyph $i of glyphs 1, 2 and 3.
     *
     * @param int-mask-of<GlyphFilter::RIGHT_TO_LEFT> $flag
     */
    private static function cursive(string $subtable, GlyphPositions $positions, int $i, int $flag = 0): void
    {
        self::attachment($subtable)
            ->cursive(
                0,
                [1, 2, 3],
                $positions,
                $i,
                new GlyphFilter(self::gdef(), $flag),
                TextDirection::LeftToRight,
            );
    }

    /** Glyph 1's exit joins glyph 2's entry, 100 units higher. */
    private static function firstJoin(): string
    {
        return LayoutSubtableBuilder::cursive([1 => [null, [500, 100]], 2 => [[0, 0], null]]);
    }

    private static function gdef(): Gdef
    {
        return new Gdef(new BinaryReader(GsubBuilder::gdef(self::CLASSES)));
    }

    /**
     * Applies a mark attachment subtable at the last glyph.
     *
     * @param list<Entry> $glyphs
     */
    private static function mark(int $type, string $subtable, array $glyphs, GlyphPositions $positions): ?int
    {
        $attachment = self::attachment($subtable);
        $ids        = array_map(
            /** @param Entry $glyph */
            static fn(array $glyph): int => $glyph[0],
            $glyphs,
        );
        $i      = count($ids) - 1;
        $filter = new GlyphFilter(self::gdef());

        return match ($type) {
            4       => $attachment->markToBase(0, $glyphs, $ids, $positions, $i, $filter),
            5       => $attachment->markToLigature(0, $glyphs, $ids, $positions, $i, $filter),
            default => $attachment->markToMark(0, $glyphs, $ids, $positions, $i, $filter),
        };
    }

    /** @param non-negative-int $count */
    private static function positions(int $count): GlyphPositions
    {
        return new GlyphPositions(array_fill(0, $count, [500, 0, 0, 0, 0, 0]));
    }

    #[Test]
    public function aChainTurnsRoundToHangFromItsNewParent(): void
    {
        $positions = self::positions(3);
        self::cursive(self::firstJoin(), $positions, 1);
        self::cursive(
            LayoutSubtableBuilder::cursive([2 => [null, [500, 50]], 3 => [[0, 0], null]]),
            $positions,
            2,
            GlyphFilter::RIGHT_TO_LEFT,
        );

        self::assertSame(
            [
                [500, 0, 0, -100, 1, GposAttachment::CURSIVE],
                [500, 0, 0, -50, 1, GposAttachment::CURSIVE],
                [500, 0, 0, 0, 0, 0],
            ],
            $positions->all(),
        );
    }

    #[Test]
    public function aGlyphJoinedTheOtherWayRoundNoLongerHangsFromItsChild(): void
    {
        $positions = self::positions(3);
        self::cursive(self::firstJoin(), $positions, 1);
        self::cursive(self::firstJoin(), $positions, 1, GlyphFilter::RIGHT_TO_LEFT);

        self::assertSame([[500, 0, 0, -100, 1, GposAttachment::CURSIVE], [500, 0, 0, 0, 0, GposAttachment::CURSIVE]], [
            $positions->get(0),
            $positions->get(1),
        ]);
    }

    #[Test]
    public function aMarkOnAWholeLigatureStacksOnAMarkOutsideIt(): void
    {
        $positions = self::positions(2);
        self::mark(
            6,
            GposBuilder::markToMark([21 => [0, [50, 0]]], [20 => [[50, 300]]]),
            [[20, [0x301]], [21, [0x302], 1, 0]],
            $positions,
        );

        self::assertSame([500, 0, 0, 300, -1, GposAttachment::MARK], $positions->get(1));
    }

    #[Test]
    public function aMarkPassesOverTheLaterGlyphsOfAMultipleSubstitution(): void
    {
        $positions = self::positions(3);
        self::mark(
            4,
            GposBuilder::markToBase([20 => [0, [50, 0]]], [1 => [[250, 700]]]),
            [[1, [0x61]], [2, []], [20, [0x301]]],
            $positions,
        );

        self::assertSame([500, 0, 200, 700, -2, GposAttachment::MARK], $positions->get(2));
    }

    #[Test]
    public function joiningTheSameGlyphsAgainLeavesTheirChain(): void
    {
        $once = self::positions(3);
        self::cursive(self::firstJoin(), $once, 1);
        $twice = self::positions(3);
        self::cursive(self::firstJoin(), $twice, 1);
        self::cursive(self::firstJoin(), $twice, 1);

        self::assertSame($once->all(), $twice->all());
    }

    /** @param list<Entry> $glyphs */
    #[Test]
    #[DataProvider('rejectionProvider')]
    public function marksWithNothingToAttachToAreLeftAlone(int $type, string $subtable, array $glyphs): void
    {
        self::assertNull(self::mark($type, $subtable, $glyphs, self::positions(count($glyphs))));
    }
}
