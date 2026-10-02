<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Color;

use Php2Pdf\Color\GradientSpread;
use Php2Pdf\Color\GradientStop;
use Php2Pdf\Color\LinearGradient;
use Php2Pdf\Color\RadialGradient;
use Php2Pdf\Color\Rgb;
use Php2Pdf\Core\Object\Dictionary;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Repeating and reflecting gradients, as SVG's spreadMethod (#37). */
#[Group('unit')]
final class GradientSpreadTest extends TestCase
{
    private static function dictionary(mixed $value): Dictionary
    {
        self::assertInstanceOf(Dictionary::class, $value);

        return $value;
    }

    private static function linear(GradientSpread $spread, float $from, float $to): LinearGradient
    {
        return new LinearGradient(0.0, 0.0, 10.0, 0.0, self::stops(), true, $spread, $from, $to);
    }

    /** @return list<GradientStop> */
    private static function stops(): array
    {
        return [new GradientStop(0.0, Rgb::fromHex('#ff0000')), new GradientStop(1.0, Rgb::fromHex('#0000ff'))];
    }

    #[Test]
    public function aPaddedGradientKeepsItsVector(): void
    {
        $shading = self::linear(GradientSpread::Pad, 0.0, 1.0)->toShading();

        self::assertSame([[0.0, 0.0, 10.0, 0.0], null], [$shading->get('Coords'), $shading->get('Domain')]);
    }

    #[Test]
    public function aRadialGradientStopsAtAZeroRadius(): void
    {
        $gradient = new RadialGradient(
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            10.0,
            self::stops(),
            true,
            GradientSpread::Repeat,
            -2.0,
            3.0,
        );

        self::assertSame([0.0, 0.0, 0.0, 0.0, 0.0, 30.0], $gradient->toShading()->get('Coords'));
    }

    #[Test]
    public function aReflectingGradientRunsBackEveryOtherLength(): void
    {
        $function = self::dictionary(self::linear(GradientSpread::Reflect, 0.0, 3.0)->toShading()->get('Function'));

        self::assertSame([0.0, 1.0, 1.0, 0.0, 0.0, 1.0], $function->get('Encode'));
    }

    #[Test]
    public function aRepeatingGradientCoversTheStretchAskedFor(): void
    {
        $shading  = self::linear(GradientSpread::Repeat, -0.5, 2.25)->toShading();
        $function = self::dictionary($shading->get('Function'));

        self::assertSame(
            [[-5.0, 0.0, 22.5, 0.0], [-0.5, 2.25], [0.0, 1.0, 2.0], [0.5, 1.0, 0.0, 1.0, 0.0, 1.0, 0.0, 0.25]],
            [$shading->get('Coords'), $shading->get('Domain'), $function->get('Bounds'), $function->get('Encode')],
        );
    }
}
