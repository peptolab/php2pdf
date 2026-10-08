<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2PdfTest\TestAsset\PngFixture;
use Php2PdfTest\TestAsset\Svg\NativeRender;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function base64_encode;
use function gzcompress;
use function preg_match;
use function rawurlencode;
use function substr_count;

/** SVG <image> elements from data URIs. */
#[Group('unit')]
final class ImageRendererTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function drawsNothingProvider(): array
    {
        $png = self::base64Png();

        return [
            'hidden'        => ["<image width=\"4\" height=\"2\" visibility=\"hidden\" href=\"{$png}\"/>"],
            'with no width' => ["<image width=\"0\" height=\"2\" href=\"{$png}\"/>"],
            'with no href'  => ['<image width="4" height="2"/>'],
        ];
    }

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function warningProvider(): array
    {
        return [
            'unsupported format' => ['data:image/gif;base64,R0lGODlhAQABAAAAACw=', ['images in unsupported formats']],
            'remote image'       => ['https://example.com/a.png', ['remote images']],
        ];
    }

    private static function base64Png(): string
    {
        return 'data:image/png;base64,' . base64_encode(self::png());
    }

    private static function png(): string
    {
        return PngFixture::bytes(
            width: 1,
            height: 1,
            depth: 8,
            type: 2,
            data: (string) gzcompress("\x00\xFF\x00\x00"),
        );
    }

    /** @param list<string> $warnings */
    #[Test]
    #[DataProvider('warningProvider')]
    public function anImageThatCannotBeReadIsLeftOutWithAWarning(string $href, array $warnings): void
    {
        $render = NativeRender::of("<image width=\"4\" height=\"2\" href=\"{$href}\"/>");

        self::assertSame($warnings, $render->warnings);
    }

    #[Test]
    #[DataProvider('drawsNothingProvider')]
    public function anImageWithNothingToShowIsNotDrawn(string $image): void
    {
        $render = NativeRender::of($image);

        self::assertSame(1, substr_count($render->pdf, needle: ' Do Q'), 'Only the SVG itself is placed.');
    }

    #[Test]
    public function aPercentEncodedDataUriIsEmbedded(): void
    {
        $render = NativeRender::of(
            '<image width="4" height="2" href="data:image/png,' . rawurlencode(self::png()) . '"/>',
        );

        self::assertStringContainsString('/Subtype/Image', $render->pdf);
    }

    #[Test]
    public function preserveAspectRatioNoneStretchesTheImageToItsBox(): void
    {
        $render = NativeRender::of(
            '<image width="4" height="2" preserveAspectRatio="none" href="' . self::base64Png() . '"/>',
        );

        self::assertSame(1, preg_match('#\nq 4 0 0 2 0 0 cm /X\d+ Do Q#', $render->pdf));
    }
}
