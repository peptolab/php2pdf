<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Image;

use Php2Pdf\Core\Filter\Flate;
use Php2Pdf\Core\Limits;
use Php2Pdf\Image\ImageOptions;
use Php2Pdf\Image\PngDecoder;
use Php2PdfTest\TestAsset\PngFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function gzcompress;
use function gzdeflate;
use function pack;

/** An image with an alpha channel is read a row at a time, its colour and alpha split. */
#[Group('unit')]
final class PngDecoderTest extends TestCase
{
    /** @return array<string, array{0: int, 1: string}> */
    public static function layoutProvider(): array
    {
        return [
            'one chunk'                    => [1, (string) gzcompress(self::scanlines())],
            'split over chunks'            => [3, (string) gzcompress(self::scanlines())],
            'raw deflate, read as a whole' => [1, (string) gzdeflate(self::scanlines())],
        ];
    }

    /**
     * Three rows of two RGBA pixels: unfiltered, then up and sub filtered, giving
     * (10 20 30 40)(50 60 70 80), (11 22 33 44)(55 66 77 88), (100 100 100 200)(110 120 130 210).
     */
    private static function scanlines(): string
    {
        return (
            pack('C*', 0, 10, 20, 30, 40, 50, 60, 70, 80)
                . pack('C*', 2, 1, 2, 3, 4, 5, 6, 7, 8)
                . pack('C*', 1, 100, 100, 100, 200, 10, 20, 30, 10)
        );
    }

    #[Test]
    #[DataProvider('layoutProvider')]
    public function colourAndAlphaAreSplitRowByRow(int $chunks, string $data): void
    {
        $image = (new PngDecoder())->decode(
            PngFixture::bytes(2, 3, 8, 6, $data, $chunks),
            Limits::default(),
            new ImageOptions(),
        );

        self::assertSame(
            [
                pack('C*', 10, 20, 30, 50, 60, 70, 11, 22, 33, 55, 66, 77, 100, 100, 100, 110, 120, 130),
                pack('C*', 40, 80, 44, 88, 200, 210),
                8,
            ],
            [Flate::decode($image->raster->data), $image->softMask?->data, $image->softMask?->bits],
        );
    }
}
