<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Fit;

use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Fit\Position;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class PositionTest extends TestCase
{
    /** @return array<string, array{0: float, 1: float}> */
    public static function outOfRangeProvider(): array
    {
        return [
            'horizontal below 0' => [-0.1, 0.5],
            'horizontal above 1' => [1.1, 0.5],
            'vertical below 0'   => [0.5, -0.1],
            'vertical above 1'   => [0.5, 1.1],
        ];
    }

    #[Test]
    #[DataProvider('outOfRangeProvider')]
    public function rejectsFractionsOutsideZeroToOne(float $horizontal, float $vertical): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Position fractions must be between 0 and 1.');
        new Position($horizontal, $vertical);
    }

    #[Test]
    public function topCenterIsHalfwayAcrossAtTheTop(): void
    {
        self::assertEquals(new Position(0.5, 1.0), Position::topCenter());
    }
}
