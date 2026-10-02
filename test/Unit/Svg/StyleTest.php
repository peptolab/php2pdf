<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

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

    #[Test]
    #[DataProvider('cascadeProvider')]
    public function theCascadeDecidesTheValue(string $css, string $attributes, float $expected): void
    {
        $rect = XmlParser::parse("<svg><rect id=\"r\" {$attributes}/></svg>")->child('rect');
        self::assertNotNull($rect);

        self::assertSame($expected, Style::root()->derive($rect, StyleSheet::fromCss($css))->strokeWidth(100.0));
    }
}
