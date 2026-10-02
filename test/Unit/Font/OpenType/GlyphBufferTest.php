<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\OpenType;

use Php2Pdf\Font\OpenType\GlyphBuffer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class GlyphBufferTest extends TestCase
{
    private static function buffer(): GlyphBuffer
    {
        return new GlyphBuffer([[1, [0x66]], [2, [0x69]], [3, [0x6C]]]);
    }

    #[Test]
    public function aPositionOutsideTheBufferHasNoGlyph(): void
    {
        self::assertSame([null, null], [self::buffer()->entry(3), self::buffer()->id(-1)]);
    }

    #[Test]
    public function aRemovedGlyphLeavesAPlaceholderUntilCompacted(): void
    {
        $buffer = self::buffer();
        $buffer->remove(1);

        self::assertSame(
            [[1, GlyphBuffer::REMOVED, 3], 3, 2],
            [$buffer->ids(), $buffer->count(), $buffer->length()],
        );
    }

    #[Test]
    public function compactingDropsThePlaceholders(): void
    {
        $buffer = self::buffer();
        $buffer->remove(1);
        $buffer->compact();

        self::assertSame([[1, 3], 2, 2], [$buffer->ids(), $buffer->count(), $buffer->length()]);
    }

    #[Test]
    public function eachLigatureHasItsOwnId(): void
    {
        $buffer = self::buffer();

        self::assertSame([1, 2], [$buffer->nextLigature(), $buffer->nextLigature()]);
    }

    #[Test]
    public function removingAPlaceholderOrNothingChangesNothing(): void
    {
        $buffer = self::buffer();
        $buffer->remove(1);
        $buffer->remove(1);
        $buffer->remove(9);

        self::assertSame([3, 2], [$buffer->count(), $buffer->length()]);
    }

    #[Test]
    public function replacingKeepsWhatTheGlyphStandsFor(): void
    {
        $buffer = self::buffer();
        $buffer->replace(1, 20);

        self::assertSame([[20, [0x69]], 20], [$buffer->entry(1), $buffer->id(1)]);
    }

    #[Test]
    public function settingAGlyphOverAPlaceholderMakesItLive(): void
    {
        $buffer = self::buffer();
        $buffer->remove(0);
        $buffer->set(0, [10, [0x66, 0x69], 1, 0, '']);

        self::assertSame([[10, 2, 3], 3], [$buffer->ids(), $buffer->length()]);
    }

    #[Test]
    public function settingAPlaceholderRemovesTheGlyph(): void
    {
        $buffer = self::buffer();
        $buffer->set(2, [GlyphBuffer::REMOVED, []]);
        $length = $buffer->length();

        self::assertSame([2, [[1, [0x66]], [2, [0x69]]]], [$length, $buffer->entries()]);
    }

    #[Test]
    public function settingOrReplacingOutsideTheBufferChangesNothing(): void
    {
        $buffer = self::buffer();
        $buffer->set(5, [10, []]);
        $buffer->replace(5, 10);

        self::assertSame([1, 2, 3], $buffer->ids());
    }

    #[Test]
    public function splicingPutsSeveralGlyphsInPlaceOfOne(): void
    {
        $buffer = self::buffer();
        $buffer->splice(1, [[20, [0x69]], [21, []]]);

        self::assertSame([[1, 20, 21, 3], 4], [$buffer->ids(), $buffer->length()]);
    }

    #[Test]
    public function theEntriesAreTheLiveGlyphs(): void
    {
        $buffer = self::buffer();
        $buffer->remove(0);

        self::assertSame([[2, [0x69]], [3, [0x6C]]], $buffer->entries());
    }
}
