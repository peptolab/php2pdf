<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2Pdf\Canvas\FillRule;
use Php2Pdf\Canvas\LineCap;
use Php2Pdf\Svg\Style;
use Php2Pdf\Svg\StyleSheet;
use Php2Pdf\Svg\XmlParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The cascade: presentation attributes, then style sheet, then style attribute, then !important. */
#[Group('unit')]
final class StyleTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: float}> */
    public static function cascadeProvider(): array
    {
        return [
            'an attribute alone'                                   => ['', 'stroke-width="2"', 2.0],
            'a rule beats an attribute'                            => [
                'rect { stroke-width: 3 }',
                'stroke-width="2"',
                3.0,
            ],
            'the style attribute beats a rule'                     => [
                '#r { stroke-width: 3 }',
                'style="stroke-width: 4"',
                4.0,
            ],
            'an important rule beats the style attribute'          => [
                'rect { stroke-width: 3 !important }',
                'style="stroke-width: 4"',
                3.0,
            ],
            'an important style attribute beats an important rule' => [
                '#r { stroke-width: 3 !important }',
                'style="stroke-width: 5 !important"',
                5.0,
            ],
        ];
    }

    /** @return array<string, array{0: string, 1: list<float>}> */
    public static function dashArrayProvider(): array
    {
        return [
            'a negative length' => ['5 -1', []],
            'an odd count'      => ['5 10 15', [5.0, 10.0, 15.0, 5.0, 10.0, 15.0]],
        ];
    }

    /** @return array<string, array{0: string, 1: bool}> */
    public static function fontWeightProvider(): array
    {
        return [
            'semi-bold'    => ['600', true],
            'heavy'        => ['900', true],
            'regular'      => ['400', false],
            'the keyword'  => ['bold', true],
            'a non-weight' => ['normal', false],
        ];
    }

    /** @return array<string, array{0: string, 1: float}> */
    public static function keywordFontSizeProvider(): array
    {
        return [
            'xx-small' => ['xx-small', 9.0],
            'x-small'  => ['x-small', 10.0],
            'small'    => ['small', 13.0],
            'medium'   => ['medium', 16.0],
            'large'    => ['large', 18.0],
            'x-large'  => ['x-large', 24.0],
            'xx-large' => ['xx-large', 32.0],
            'smaller'  => ['smaller', 16.0 / 1.2],
            'larger'   => ['larger', 16.0 * 1.2],
            'unknown'  => ['enormous', 16.0],
        ];
    }

    private static function styleOf(string $attributes, string $element = 'rect'): Style
    {
        $node = XmlParser::parse("<svg><{$element} {$attributes}/></svg>")->child($element);
        self::assertNotNull($node);

        return Style::root()->derive($node, StyleSheet::fromCss(''));
    }

    /** @param list<float> $expected */
    #[Test]
    #[DataProvider('dashArrayProvider')]
    public function aDashArrayIsDroppedWhenNegativeAndRepeatedWhenOdd(string $value, array $expected): void
    {
        self::assertSame($expected, self::styleOf("stroke-dasharray=\"{$value}\"")->dashArray());
    }

    #[Test]
    #[DataProvider('fontWeightProvider')]
    public function aFontWeightOfSixHundredOrMoreIsBold(string $weight, bool $bold): void
    {
        self::assertSame($bold, self::styleOf("font-weight=\"{$weight}\"")->isBold());
    }

    #[Test]
    #[DataProvider('keywordFontSizeProvider')]
    public function aKeywordFontSizeMapsToItsCssPixels(string $keyword, float $expected): void
    {
        self::assertEqualsWithDelta($expected, self::styleOf("font-size=\"{$keyword}\"")->fontSize(), 1.0e-9);
    }

    #[Test]
    public function anEmptyFontFamilyEntryIsSkipped(): void
    {
        self::assertSame(['Arial', 'serif'], self::styleOf('font-family="Arial, , serif"')->fontFamilies());
    }

    #[Test]
    public function anEvenOddClipRuleIsRead(): void
    {
        self::assertSame(FillRule::EvenOdd, self::styleOf('clip-rule="evenodd"')->clipRule());
    }

    #[Test]
    public function aPercentageOpacityIsAFraction(): void
    {
        self::assertSame(0.5, self::styleOf('opacity="50%"')->opacity());
    }

    #[Test]
    public function aSquareLineCapIsRead(): void
    {
        self::assertSame(LineCap::Square, self::styleOf('stroke-linecap="square"')->lineCap());
    }

    #[Test]
    public function inheritOfAPropertyTheParentLacksLeavesItUnset(): void
    {
        self::assertNull(self::styleOf('stroke="inherit"')->get('stroke'));
    }

    #[Test]
    public function inheritTakesTheParentValue(): void
    {
        self::assertSame('black', self::styleOf('fill="inherit"')->get('fill'));
    }

    #[Test]
    public function stopOpacityIsMultipliedByTheStopColourAlpha(): void
    {
        self::assertSame(
            0.25,
            self::styleOf('stop-opacity="0.5" stop-color="rgba(0, 0, 0, 0.5)"', element: 'stop')->stopOpacity(),
        );
    }

    #[Test]
    #[DataProvider('cascadeProvider')]
    public function theCascadeDecidesTheValue(string $css, string $attributes, float $expected): void
    {
        $rect = XmlParser::parse("<svg><rect id=\"r\" {$attributes}/></svg>")->child('rect');
        self::assertNotNull($rect);

        self::assertSame($expected, Style::root()->derive($rect, StyleSheet::fromCss($css))->strokeWidth(100.0));
    }
}
