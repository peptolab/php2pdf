<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2PdfTest\TestAsset\Svg\NativeRender;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** How the native converter walks the SVG tree: viewports, <use> and transforms. */
#[Group('unit')]
final class RendererTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function drawsNothingProvider(): array
    {
        return [
            'nested svg with no width' => ['<svg width="0" height="5"><rect width="3" height="3"/></svg>'],
            'use of a missing element' => ['<use href="#missing" fill="red"/>'],
        ];
    }

    #[Test]
    public function aNestedSvgWithVisibleOverflowIsNotClipped(): void
    {
        $render = NativeRender::of('<svg width="5" height="5" overflow="visible"><rect width="3" height="3"/></svg>');

        self::assertStringNotContainsString('W n', $render->pdf);
    }

    #[Test]
    public function aSymbolsOwnTransformAppliesBeforeItsViewportClip(): void
    {
        $render = NativeRender::of(
            '<defs><symbol id="s" transform="translate(4 0)"><rect width="1" height="1"/></symbol></defs>'
                . '<use href="#s" width="5" height="5"/>',
        );

        self::assertStringContainsString("1 0 0 1 4 0 cm\n0 0 m\n5 0 l\n5 5 l\n0 5 l\nh\nW n", $render->pdf);
    }

    #[Test]
    #[DataProvider('drawsNothingProvider')]
    public function drawsNothingForAnElementWithNothingToShow(string $content): void
    {
        $render = NativeRender::of($content);

        self::assertStringNotContainsString(' rg', $render->pdf);
    }
}
