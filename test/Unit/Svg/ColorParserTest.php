<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2Pdf\Svg\ColorParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** SVG colours, with alpha read separately as opacity (#37). */
#[Group('unit')]
final class ColorParserTest extends TestCase
{
    /** @return array<string, array{0: string, 1: array{0: float, 1: float, 2: float}, 2: float}> */
    public static function colourProvider(): array
    {
        return [
            'hex'                       => ['#ff8000', [1.0, 128 / 255, 0.0], 1.0],
            'short hex with alpha'      => ['#f008', [1.0, 0.0, 0.0], 0x88 / 255],
            'hex with alpha'            => ['#0000ff40', [0.0, 0.0, 1.0], 0x40 / 255],
            'rgba with commas'          => ['rgba(0, 128, 255, 0.25)', [0.0, 128 / 255, 1.0], 0.25],
            'rgb with spaces and alpha' => ['rgb(255 0 0 / 50%)', [1.0, 0.0, 0.0], 0.5],
            'rgb in percent'            => ['rgb(100%, 50%, 0%)', [1.0, 0.5, 0.0], 1.0],
            'hsl'                       => ['hsl(120, 100%, 25%)', [0.0, 0.5, 0.0], 1.0],
            'hsla with spaces'          => ['hsla(240 100% 50% / .5)', [0.0, 0.0, 1.0], 0.5],
            'hsl in turns'              => ['hsl(0.5turn 100% 50%)', [0.0, 1.0, 1.0], 1.0],
            'hsl hue past a turn'       => ['hsl(480, 100%, 50%)', [0.0, 1.0, 0.0], 1.0],
            'named'                     => ['rebeccapurple', [0x66 / 255, 0x33 / 255, 0x99 / 255], 1.0],
            'hsl red'                   => ['hsl(0, 100%, 50%)', [1.0, 0.0, 0.0], 1.0],
            'hsl magenta'               => ['hsl(300, 100%, 50%)', [1.0, 0.0, 1.0], 1.0],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function malformedColourProvider(): array
    {
        return [
            'hex of the wrong length' => ['#ff'],
            'rgb with two channels'   => ['rgb(1, 2)'],
            'hsl with two arguments'  => ['hsl(120, 50%)'],
        ];
    }

    #[Test]
    #[DataProvider('malformedColourProvider')]
    public function aMalformedColourIsNotAColour(string $value): void
    {
        self::assertNull(ColorParser::color($value));
    }

    #[Test]
    public function currentColorTakesTheAlphaOfTheColorProperty(): void
    {
        self::assertSame(0.25, ColorParser::alpha('currentColor', 'rgba(0, 0, 0, 0.25)'));
    }

    #[Test]
    public function currentColorWithoutAColorPropertyIsOpaque(): void
    {
        self::assertSame(1.0, ColorParser::alpha('currentColor'));
    }

    #[Test]
    public function noPaintValueIsNoPaint(): void
    {
        self::assertNull(ColorParser::paint(null));
    }

    #[Test]
    public function paintServersAndUnknownValuesAreOpaque(): void
    {
        self::assertSame([1.0, 1.0, null], [
            ColorParser::alpha('url(#g)'),
            ColorParser::alpha('nonsense'),
            ColorParser::color('nonsense'),
        ]);
    }

    /** @param array{0: float, 1: float, 2: float} $rgb */
    #[Test]
    #[DataProvider('colourProvider')]
    public function readsTheColourAndItsAlpha(string $value, array $rgb, float $alpha): void
    {
        $colour = ColorParser::color($value);
        self::assertNotNull($colour);

        self::assertEqualsWithDelta(
            [...$rgb, $alpha],
            [$colour->red, $colour->green, $colour->blue, ColorParser::alpha($value)],
            1.0e-9,
        );
    }
}
