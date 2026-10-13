<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Color;

use Php2Pdf\Color\Cmyk;
use Php2Pdf\Color\ColorInterface;
use Php2Pdf\Color\Gray;
use Php2Pdf\Color\IccColor;
use Php2Pdf\Color\Lab;
use Php2Pdf\Color\Rgb;
use Php2Pdf\Color\Spot;
use Php2PdfTest\TestAsset\StubIccProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ComponentCountTest extends TestCase
{
    /** @return array<string, array{0: ColorInterface, 1: int}> */
    public static function colorProvider(): array
    {
        $profile = StubIccProfile::of('GRAY');

        return [
            'gray'      => [new Gray(0.5), 1],
            'rgb'       => [new Rgb(0.1, 0.2, 0.3), 3],
            'cmyk'      => [new Cmyk(0.1, 0.2, 0.3, 0.4), 4],
            'lab'       => [new Lab(50.0, 10.0, -10.0), 3],
            'spot'      => [new Spot('Fictional Orange', new Cmyk(0.0, 0.5, 1.0, 0.0)), 1],
            'icc based' => [new IccColor($profile, 0.5), 1],
        ];
    }

    #[Test]
    #[DataProvider('colorProvider')]
    public function reportsTheComponentsOfItsColourSpace(ColorInterface $color, int $expected): void
    {
        self::assertSame($expected, $color->componentCount());
    }
}
