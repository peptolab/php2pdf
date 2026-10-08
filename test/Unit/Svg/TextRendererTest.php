<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2Pdf\Svg\SvgFile;
use Php2PdfTest\TestAsset\Svg\NativeRender;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match_all;

/** SVG <text> and <tspan> set as real glyphs. */
#[Group('unit')]
final class TextRendererTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function onlyTheTextIsShownProvider(): array
    {
        return [
            'tspan not displayed' => ['<text x="1" y="5">A<tspan display="none">B</tspan></text>'],
            'tspan hidden'        => ['<text x="1" y="5">A<tspan visibility="hidden">B</tspan></text>'],
        ];
    }

    #[Test]
    public function aBlankXStartsTheTextAtZero(): void
    {
        $render = NativeRender::of('<text x=" " y="5">A</text>');

        self::assertStringContainsString('1 0 0 -1 0 5 Tm (A) Tj', $render->pdf);
    }

    #[Test]
    public function anEmptyTextNodeDrawsNothing(): void
    {
        $svg = SvgFile::fromData(
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><text/></svg>',
        );
        $text           = $svg->root()->child('text') ?? self::fail('The SVG has no <text>.');
        $text->children = [''];

        self::assertStringNotContainsString(' Tj', NativeRender::ofFile($svg)->pdf);
    }

    #[Test]
    public function aTspanWithAYMovesDownButKeepsTheCurrentX(): void
    {
        $render = NativeRender::of('<text x="1" y="5">A<tspan y="8">B</tspan></text>');

        self::assertStringContainsString('1 0 0 -1 11.672 8 Tm (B) Tj', $render->pdf);
    }

    #[Test]
    public function fillOpacityAppliesToText(): void
    {
        $render = NativeRender::of('<text x="1" y="5" fill-opacity="0.5">A</text>');

        self::assertStringContainsString('/ca 0.5', $render->pdf);
    }

    #[Test]
    #[DataProvider('onlyTheTextIsShownProvider')]
    public function onlyTheShownRunsAreDrawn(string $content): void
    {
        $render = NativeRender::of($content);

        self::assertSame(1, preg_match_all('# Tj ET#', $render->pdf));
    }

    #[Test]
    public function strokedTextIsFilledWithAWarning(): void
    {
        $render = NativeRender::of('<text x="1" y="5" stroke="red">A</text>');

        self::assertSame(['stroked text'], $render->warnings);
    }

    #[Test]
    public function textWithoutAFillIsNotDrawn(): void
    {
        $render = NativeRender::of('<text x="1" y="5" fill="none">A</text>');

        self::assertStringNotContainsString(' Tj', $render->pdf);
    }
}
