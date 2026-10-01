<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Geometry;

use PhpPdf\Exception\InvalidArgumentException;
use PhpPdf\Geometry\Box;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class BoxTest extends TestCase
{
    /** @return array<string, array{0: Box, 1: float, 2: bool}> */
    public static function enclosureProvider(): array
    {
        return [
            'inside'              => [new Box(10.0, 10.0, 50.0, 50.0), 0.0, true],
            'the same box'        => [new Box(0.0, 0.0, 100.0, 100.0), 0.0, true],
            'past the left edge'  => [new Box(-1.0, 10.0, 50.0, 50.0), 0.0, false],
            'past the bottom'     => [new Box(10.0, -1.0, 50.0, 50.0), 0.0, false],
            'past the right edge' => [new Box(60.0, 10.0, 50.0, 50.0), 0.0, false],
            'past the top'        => [new Box(10.0, 60.0, 50.0, 50.0), 0.0, false],
            'within tolerance'    => [new Box(-0.005, 0.0, 100.01, 100.0), 0.01, true],
        ];
    }

    #[Test]
    public function convertsUnits(): void
    {
        $box = Box::fromMm(10, 20, 30, 40);

        self::assertEqualsWithDelta(28.3465, $box->x, 0.001);
        self::assertEqualsWithDelta(56.6929, $box->y, 0.001);
        self::assertEqualsWithDelta(85.0394, $box->width, 0.001);
        self::assertEqualsWithDelta(113.3858, $box->height, 0.001);
        self::assertSame([72.0, 144.0, 216.0, 360.0], Box::fromIn(1, 2, 2, 3)->toRect());
    }

    #[Test]
    #[DataProvider('enclosureProvider')]
    public function enclosesBoxesWithinItsEdges(Box $other, float $tolerance, bool $expected): void
    {
        self::assertSame($expected, (new Box(0.0, 0.0, 100.0, 100.0))->encloses($other, $tolerance));
    }

    #[Test]
    public function insetsAndIntersects(): void
    {
        $box = new Box(0, 0, 100, 50);

        self::assertSame([10.0, 10.0, 90.0, 40.0], $box->inset(10)->toRect());
        self::assertSame([1.0, 4.0, 97.0, 48.0], $box->inset(1, 2, 3, 4)->toRect());
        self::assertSame([50.0, 25.0, 100.0, 50.0], $box->intersect(new Box(50, 25, 100, 100))->toRect());
        self::assertTrue($box->intersect(new Box(200, 200, 10, 10))->isEmpty());
        self::assertSame([0.0, 0.0, 200.0, 210.0], $box->union(new Box(200, 200, 0, 10))->toRect());
    }

    #[Test]
    public function normalisesCorners(): void
    {
        $box = Box::fromCorners(100, 200, 10, 20);

        self::assertSame([10.0, 20.0, 100.0, 200.0], $box->toRect());
    }

    #[Test]
    public function rejectsNegativeSizes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Box(0, 0, -1, 1);
    }
}
