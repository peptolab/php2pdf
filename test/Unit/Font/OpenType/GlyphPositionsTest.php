<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\OpenType;

use Php2Pdf\Font\OpenType\GlyphPositions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class GlyphPositionsTest extends TestCase
{
    private const array POSITION = [500, 0, 0, 0, 0, 0];

    /** @return array<string, array{0: int}> */
    public static function outOfRangeProvider(): array
    {
        return ['before the first glyph' => [-1], 'after the last glyph' => [1]];
    }

    #[Test]
    #[DataProvider('outOfRangeProvider')]
    public function addingToAPositionOutOfRangeChangesNothing(int $i): void
    {
        $positions = new GlyphPositions([self::POSITION]);
        $positions->add($i, xAdvance: 10, yAdvance: 20, xOffset: 30, yOffset: 40);

        self::assertSame([self::POSITION], $positions->all());
    }

    #[Test]
    public function itCountsTheGlyphs(): void
    {
        self::assertSame(2, (new GlyphPositions([self::POSITION, self::POSITION]))->count());
    }

    #[Test]
    #[DataProvider('outOfRangeProvider')]
    public function settingAPositionOutOfRangeChangesNothing(int $i): void
    {
        $positions = new GlyphPositions([self::POSITION]);
        $positions->set($i, [1, 2, 3, 4, 5, 6]);

        self::assertSame([self::POSITION], $positions->all());
    }
}
