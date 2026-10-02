<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Color;

use Php2Pdf\Color\Cmyk;
use Php2Pdf\Color\GradientStop;
use Php2Pdf\Color\Gray;
use Php2Pdf\Color\IccColor;
use Php2Pdf\Color\IccProfile;
use Php2Pdf\Color\LinearGradient;
use Php2Pdf\Color\Rgb;
use Php2Pdf\Exception\InvalidArgumentException;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_pad;
use function str_repeat;

#[Group('unit')]
final class ColorTest extends TestCase
{
    #[Test]
    public function convertsPercentages(): void
    {
        self::assertSame([0.1, 0.2, 0.3, 0.4], Cmyk::fromPercent(10, 20, 30, 40)->components());
        self::assertSame([0.0], Gray::black()->components());
    }

    #[Test]
    public function gradientStopsAreSorted(): void
    {
        $gradient = new LinearGradient(0, 0, 1, 1, [
            new GradientStop(1, new Gray(1)),
            new GradientStop(0, new Gray(0)),
        ]);

        self::assertSame(0.0, $gradient->stops[0]->offset);
        self::assertSame(1.0, $gradient->stops[1]->offset);
    }

    #[Test]
    public function gradientStopsMustShareAColourSpace(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new LinearGradient(0, 0, 1, 1, [new GradientStop(0, new Gray(0)), new GradientStop(1, Rgb::fromHex('#fff'))]);
    }

    #[Test]
    public function parsesHexColours(): void
    {
        self::assertSame([1.0, 0.0, 0.0], Rgb::fromHex('#f00')->components());
        self::assertSame([0.0, 0.0, 1.0], Rgb::fromHex('0000ff')->components());

        $this->expectException(InvalidArgumentException::class);
        Rgb::fromHex('#12345');
    }

    #[Test]
    public function readsProfileComponentCount(): void
    {
        $profile = new IccProfile(str_pad(str_repeat("\0", times: 16) . 'CMYK', length: 128, pad_string: "\0"));
        self::assertSame(4, $profile->componentCount);

        $this->expectException(InvalidArgumentException::class);
        new IccColor($profile, 0.1, 0.2);
    }
}
