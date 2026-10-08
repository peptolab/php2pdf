<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Geometry;

use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Geometry\Dimensions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class DimensionsTest extends TestCase
{
    /** @return array<string, array{0: float, 1: float}> */
    public static function nonPositiveProvider(): array
    {
        return [
            'zero width'      => [0.0, 10.0],
            'negative height' => [10.0, -1.0],
        ];
    }

    /** @return array<string, array{0: Dimensions, 1: array{0: float, 1: float}}> */
    public static function portraitProvider(): array
    {
        return [
            'landscape is turned'   => [Dimensions::pt(200.0, 100.0), [100.0, 200.0]],
            'portrait is unchanged' => [Dimensions::pt(100.0, 200.0), [100.0, 200.0]],
            'square is unchanged'   => [Dimensions::pt(100.0, 100.0), [100.0, 100.0]],
        ];
    }

    #[Test]
    #[DataProvider('nonPositiveProvider')]
    public function refusesSizesThatAreNotPositive(float $width, float $height): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Page dimensions must be positive.');
        new Dimensions($width, $height);
    }

    /**
     * @param array{0: float, 1: float} $expected
     */
    #[Test]
    #[DataProvider('portraitProvider')]
    public function turnsToPortrait(Dimensions $dimensions, array $expected): void
    {
        $portrait = $dimensions->portrait();

        self::assertSame($expected, [$portrait->width, $portrait->height]);
    }
}
