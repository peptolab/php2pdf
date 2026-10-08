<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2PdfTest\TestAsset\Svg\NativeRender;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** SVG gradients resolved from their stops and href chains. */
#[Group('unit')]
final class PaintServersTest extends TestCase
{
    #[Test]
    public function aGradientChainWithoutStopsFallsBackToTheFallbackColour(): void
    {
        $render = NativeRender::of(
            '<defs><linearGradient id="g"><desc>no stops</desc></linearGradient>'
                . '<linearGradient id="h" href="#g"/></defs>'
                . '<rect width="10" height="10" fill="url(#h) blue"/>',
        );

        self::assertStringContainsString("0 0 1 rg\n", $render->pdf);
    }

    #[Test]
    public function aGradientWithASingleStopPaintsThatColourThroughout(): void
    {
        $render = NativeRender::of(
            '<defs><linearGradient id="g"><stop offset="0.3" stop-color="red"/></linearGradient></defs>'
                . '<rect width="10" height="10" fill="url(#g)"/>',
        );

        self::assertStringContainsString('/C0[1 0 0]/C1[1 0 0]', $render->pdf);
    }
}
