<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Geometry;

use Php2Pdf\Geometry\Unit;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class UnitTest extends TestCase
{
    /** @return array<string, array{0: callable(float): float, 1: float, 2: float}> */
    public static function conversionProvider(): array
    {
        return [
            'centimetres to points' => [Unit::cm(...), 2.54, 72.0],
            'inches to points'      => [Unit::in(...), 2.0, 144.0],
            'millimetres to points' => [Unit::mm(...), 25.4, 72.0],
            'points to points'      => [Unit::pt(...), 12.5, 12.5],
            'points to inches'      => [Unit::toIn(...), 144.0, 2.0],
            'points to millimetres' => [Unit::toMm(...), 72.0, 25.4],
        ];
    }

    /**
     * @param callable(float): float $convert
     */
    #[Test]
    #[DataProvider('conversionProvider')]
    public function convertsBetweenUnitsAndPoints(callable $convert, float $value, float $expected): void
    {
        self::assertEqualsWithDelta($expected, $convert($value), 1e-9);
    }
}
