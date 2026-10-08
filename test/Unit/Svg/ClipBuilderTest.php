<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2PdfTest\TestAsset\Svg\NativeRender;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** SVG <clipPath> elements applied as PDF clipping paths. */
#[Group('unit')]
final class ClipBuilderTest extends TestCase
{
    private const string CLIP_EVERYTHING = "0 0 m\nh\nW n";

    /** @return array<string, array{0: string, 1: string}> */
    public static function boundingBoxProvider(): array
    {
        return [
            'use offset by x and y'       => [
                '<use href="#r" x="2" y="2" clip-path="url(#c)"/>',
                "3 3 m\n5 3 l\n5 4 l\n3 4 l\nh\nW n",
            ],
            'use of a missing element'    => [
                '<use href="#missing" clip-path="url(#c)"/>',
                "0 0 m\n0.5 0 l\n0.5 0.5 l\n0 0.5 l\nh\nW n",
            ],
            'image'                       => [
                '<image x="2" y="2" width="4" height="6" clip-path="url(#c)"/>',
                "2 2 m\n4 2 l\n4 5 l\n2 5 l\nh\nW n",
            ],
            'image without a width'       => [
                '<image width="0" height="6" clip-path="url(#c)"/>',
                "0 0 m\n0.5 0 l\n0.5 0.5 l\n0 0.5 l\nh\nW n",
            ],
            'group whose text is ignored' => [
                '<g clip-path="url(#c)"><text>x</text><rect x="2" y="2" width="4" height="4"/></g>',
                "2 2 m\n4 2 l\n4 4 l\n2 4 l\nh\nW n",
            ],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function nothingUsableProvider(): array
    {
        return [
            'empty clip path'        => [''],
            'use of a missing shape' => ['<use href="#nothing"/>'],
            'use without a href'     => ['<use/>'],
            'use of a group'         => ['<use href="#g"/>'],
            'hidden shape'           => ['<rect width="5" height="5" display="none"/>'],
            'shape with no area'     => ['<rect width="0" height="5"/>'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function unusableReferenceProvider(): array
    {
        return [
            'missing element' => ['url(#none)'],
            'not a clip path' => ['url(#r)'],
        ];
    }

    #[Test]
    #[DataProvider('unusableReferenceProvider')]
    public function aClipPathReferenceToSomethingElseLeavesTheElementUnclipped(string $reference): void
    {
        $render = NativeRender::of(
            "<defs><rect id=\"r\" width=\"1\" height=\"1\"/></defs><rect width=\"10\" height=\"10\" clip-path=\"{$reference}\"/>",
        );

        self::assertStringNotContainsString('W n', $render->pdf);
    }

    #[Test]
    public function aClipPathUseTakesItsTargetsTransformThenItsOffset(): void
    {
        $render = NativeRender::of(
            '<defs><rect id="r" width="1" height="1" transform="scale(2)"/>'
                . '<clipPath id="c"><use href="#r" x="2" y="3"/></clipPath></defs>'
                . '<rect width="10" height="10" clip-path="url(#c)"/>',
        );

        self::assertStringContainsString("2 3 m\n4 3 l\n4 5 l\n2 5 l\nh\nW n", $render->pdf);
    }

    #[Test]
    #[DataProvider('nothingUsableProvider')]
    public function aClipPathWithNothingUsableInItClipsEverythingAway(string $content): void
    {
        $render = NativeRender::of(
            "<defs><g id=\"g\"/><clipPath id=\"c\">{$content}</clipPath></defs>"
                . '<rect width="10" height="10" clip-path="url(#c)"/>',
        );

        self::assertStringContainsString(self::CLIP_EVERYTHING, $render->pdf);
    }

    #[Test]
    #[DataProvider('boundingBoxProvider')]
    public function anObjectBoundingBoxClipPathScalesToTheClippedElementsBounds(string $clipped, string $clip): void
    {
        $render = NativeRender::of(
            '<defs><rect id="r" x="1" y="1" width="4" height="2"/><clipPath id="c" clipPathUnits="objectBoundingBox">'
                . "<rect width=\"0.5\" height=\"0.5\"/></clipPath></defs>{$clipped}",
        );

        self::assertStringContainsString($clip, $render->pdf);
    }

    #[Test]
    public function textInAClipPathIsLeftOutWithAWarning(): void
    {
        $render = NativeRender::of(
            '<defs><clipPath id="c"><text>hi</text></clipPath></defs><rect width="10" height="10" clip-path="url(#c)"/>',
        );

        self::assertSame(['text in clip paths'], $render->warnings);
    }
}
