<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Image;

use Php2Pdf\Core\Filter\Flate;
use Php2Pdf\Core\Limits;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Image\ImageData;
use Php2Pdf\Image\ImageOptions;
use Php2Pdf\Image\PngDecoder;
use Php2PdfTest\TestAsset\Image\PngChunks;
use Php2PdfTest\TestAsset\PngFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function gzcompress;
use function gzdeflate;
use function pack;

/**
 * PNG files are read from their chunks; an image with an alpha channel is read a row at a time, its
 * colour and alpha split.
 */
#[Group('unit')]
final class PngDecoderTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string|null}> */
    public static function iccProfileProvider(): array
    {
        return [
            'compressed profile'         => ["sRGB\0\0" . (string) gzcompress('profile'), 'profile'],
            'no name terminator'         => ['sRGB', null],
            'nothing after the name'     => ["sRGB\0\0", null],
            'data that will not inflate' => ["sRGB\0\0\xFF\xFF\xFF\xFF", null],
        ];
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function layoutProvider(): array
    {
        return [
            'one chunk'                    => [1, (string) gzcompress(self::scanlines())],
            'split over chunks'            => [3, (string) gzcompress(self::scanlines())],
            'raw deflate, read as a whole' => [1, (string) gzdeflate(self::scanlines())],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function malformedHeaderProvider(): array
    {
        return [
            'truncated header' => [PngChunks::chunk('IHDR', pack('NN', 1, 1)), 'PNG IHDR chunk is truncated.'],
            'no pixels'        => [
                PngChunks::header(
                    width: 0,
                    height: 1,
                ),
                'PNG has no pixels.',
            ],
        ];
    }

    private static function decode(string $data): ImageData
    {
        return (new PngDecoder())->decode($data, Limits::default(), new ImageOptions());
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
    public function aColourKeyTooShortForRgbIsIgnored(): void
    {
        $image = self::decode(PngChunks::file(
            PngChunks::header(
                width: 1,
                height: 1,
                depth: 8,
                type: 2,
            ),
            PngChunks::chunk('tRNS', pack('n', 5)),
            PngChunks::imageData("\0\x01\x02\x03"),
        ));

        self::assertNull($image->raster->colorKeyMask);
    }

    #[Test]
    #[DataProvider('malformedHeaderProvider')]
    public function aMalformedHeaderIsRefused(string $ihdr, string $message): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage($message);

        self::decode(PngChunks::file($ihdr, PngChunks::imageData("\0\x80")));
    }

    #[Test]
    #[DataProvider('iccProfileProvider')]
    public function anEmbeddedIccProfileIsInflatedOrIgnoredWhenMalformed(string $iccp, ?string $expected): void
    {
        $image = self::decode(PngChunks::file(
            PngChunks::header(
                width: 1,
                height: 1,
            ),
            PngChunks::chunk('iCCP', $iccp),
            PngChunks::imageData("\0\x80"),
        ));

        self::assertSame($expected, $image->raster->iccProfile);
    }

    #[Test]
    public function aPhysicalSizeWithoutAUnitGivesTheDefaultResolution(): void
    {
        $image = self::decode(PngChunks::file(
            PngChunks::header(
                width: 1,
                height: 1,
            ),
            PngChunks::chunk('pHYs', pack('NNC', 5000, 5000, 0)),
            PngChunks::imageData("\0\x80"),
        ));

        self::assertSame([72.0, 72.0], [$image->dpiX, $image->dpiY]);
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

    #[Test]
    public function dataThatIsNotAPngIsRefused(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Not a PNG file.');

        self::decode('GIF89a');
    }
}
