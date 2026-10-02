<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Matrix;
use Php2Pdf\Svg\Units;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class UnitsTest extends TestCase
{
    /** @return array<string, array{0: ?string, 1: Units, 2: Units}> */
    public static function attributeProvider(): array
    {
        return [
            'user space'                    => ['userSpaceOnUse', Units::ObjectBoundingBox, Units::UserSpaceOnUse],
            'bounding box'                  => ['objectBoundingBox', Units::UserSpaceOnUse, Units::ObjectBoundingBox],
            'spaces around'                 => [' userSpaceOnUse ', Units::ObjectBoundingBox, Units::UserSpaceOnUse],
            'no attribute: the default'     => [null, Units::ObjectBoundingBox, Units::ObjectBoundingBox],
            'an unknown value: the default' => ['pixels', Units::UserSpaceOnUse, Units::UserSpaceOnUse],
        ];
    }

    #[Test]
    public function aBoundingBoxRegionIsFractionsOfTheBox(): void
    {
        self::assertEquals(
            new Box(6.0, 3.0, 48.0, 24.0),
            Units::ObjectBoundingBox->region(
                '-10%',
                '-10%',
                '1.2',
                '120%',
                new Box(10.0, 5.0, 40.0, 20.0),
                200.0,
                100.0,
            ),
        );
    }

    #[Test]
    #[DataProvider('attributeProvider')]
    public function anAttributeNamesItsUnits(?string $value, Units $default, Units $expected): void
    {
        self::assertSame($expected, Units::of($value, $default));
    }

    #[Test]
    public function aUserSpaceRegionIsLengthsAgainstTheViewport(): void
    {
        self::assertEquals(
            new Box(20.0, 10.0, 100.0, 7.0),
            Units::UserSpaceOnUse->region('10%', '10%', '50%', '7', new Box(10.0, 5.0, 40.0, 20.0), 200.0, 100.0),
        );
    }

    #[Test]
    public function boundingBoxContentIsMappedOntoTheBox(): void
    {
        self::assertEquals(
            [new Matrix(40.0, 0.0, 0.0, 20.0, 10.0, 5.0), Matrix::identity()],
            [
                Units::ObjectBoundingBox->boxMatrix(new Box(10.0, 5.0, 40.0, 20.0)),
                Units::UserSpaceOnUse->boxMatrix(new Box(10.0, 5.0, 40.0, 20.0)),
            ],
        );
    }
}
