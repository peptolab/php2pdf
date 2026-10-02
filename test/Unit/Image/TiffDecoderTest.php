<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Image;

use Php2Pdf\Core\Limits;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Image\ImageData;
use Php2Pdf\Image\ImageOptions;
use Php2Pdf\Image\ImageTraits;
use Php2Pdf\Image\TiffDecoder;
use Php2PdfTest\TestAsset\TiffFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_repeat;

/** Baseline TIFF read natively, keeping CMYK, palettes and profiles (#26). */
#[Group('unit')]
final class TiffDecoderTest extends TestCase
{
    /** @return array<string, array{0: TiffFixture, 1: array{0: string, 1: int, 2: string}}> colour space, depth, samples */
    public static function layoutProvider(): array
    {
        $rgb  = str_repeat("\xC8\x1E\x1E", times: 8);
        $cmyk = str_repeat("\x00\x80\xFF\x10", times: 8);

        return [
            'RGB'                                   => [new TiffFixture(4, 2, 2, 8, 3, $rgb), ['DeviceRGB', 8, $rgb]],
            'RGB, big-endian'                       => [
                (new TiffFixture(4, 2, 2, 8, 3, $rgb))->bigEndian(),
                ['DeviceRGB', 8, $rgb],
            ],
            'RGB, Deflate'                          => [
                (new TiffFixture(4, 2, 2, 8, 3, $rgb))->compressed(8),
                ['DeviceRGB', 8, $rgb],
            ],
            'RGB, PackBits'                         => [
                (new TiffFixture(4, 2, 2, 8, 3, $rgb))->compressed(32_773),
                ['DeviceRGB', 8, $rgb],
            ],
            'CMYK'                                  => [
                new TiffFixture(4, 2, 5, 8, 4, $cmyk),
                ['DeviceCMYK', 8, $cmyk],
            ],
            'grey, 1 bit'                           => [
                new TiffFixture(8, 2, 1, 1, 1, "\xAA\x55"),
                ['DeviceGray', 1, "\xAA\x55"],
            ],
            '16-bit grey, little-endian'            => [
                new TiffFixture(2, 1, 1, 16, 1, "\x34\x12\x78\x56"),
                ['DeviceGray', 16, "\x12\x34\x56\x78"],
            ],
            '16-bit grey, big-endian'               => [
                (new TiffFixture(2, 1, 1, 16, 1, "\x12\x34\x56\x78"))->bigEndian(),
                ['DeviceGray', 16, "\x12\x34\x56\x78"],
            ],
            '16-bit grey, predictor, little-endian' => [
                (new TiffFixture(2, 1, 1, 16, 1, "\x34\x12\x44\x44"))->withTags([317 => [3, [2]]]),
                ['DeviceGray', 16, "\x12\x34\x56\x78"],
            ],
            '16-bit grey, predictor, big-endian'    => [
                (new TiffFixture(2, 1, 1, 16, 1, "\x12\x34\x44\x44"))->bigEndian()
                    ->withTags([317 => [3, [2]]]),
                ['DeviceGray', 16, "\x12\x34\x56\x78"],
            ],
            '16-bit predictor wrapping past 65535'  => [
                (new TiffFixture(2, 1, 1, 16, 1, "\xFF\xF0\x00\x20"))->bigEndian()
                    ->withTags([317 => [3, [2]]]),
                ['DeviceGray', 16, "\xFF\xF0\x00\x10"],
            ],
        ];
    }

    /** @return array<string, array{0: TiffFixture}> */
    public static function unsupportedProvider(): array
    {
        $rgb = str_repeat("\x00", times: 24);

        return [
            'tiles'               => [(new TiffFixture(4, 2, 2, 8, 3, $rgb))->withTags([322 => [3, [16]]])],
            'JPEG compression'    => [(new TiffFixture(4, 2, 2, 8, 3, $rgb))->compressed(7)],
            'YCbCr'               => [new TiffFixture(4, 2, 6, 8, 3, $rgb)],
            'premultiplied alpha' => [(new TiffFixture(2, 1, 2, 8, 4, str_repeat("\x00", times: 8)))->withTags([
                338 => [3, [1]],
            ])],
            'separate planes'     => [(new TiffFixture(4, 2, 2, 8, 3, $rgb))->separatePlanes()],
        ];
    }

    private static function decode(TiffFixture $tiff, int $page = 1): ImageData
    {
        $data = (new TiffDecoder())->decode($tiff->bytes(), Limits::default(), new ImageOptions(page: $page));
        self::assertInstanceOf(ImageData::class, $data);

        return $data;
    }

    #[Test]
    public function aMissingPageIsAnError(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('The image has 2 pages; page 3 was asked for.');
        self::decode((new TiffFixture(1, 1, 1, 8, 1, "\x00"))->pages(2), page: 3);
    }

    #[Test]
    public function aPaletteBecomesAnIndexedColourSpace(): void
    {
        $map  = [65_535, 0, 0, 65_535, 0, 0, 0, 0, 0, 0, 65_535, 0];
        $data = self::decode((new TiffFixture(4, 1, 3, 2, 1, "\x1B"))->withTags([320 => [3, $map]]));

        self::assertSame("\xFF\x00\x00\x00\x00\x00\x00\x00\xFF\xFF\x00\x00", $data->raster->colorSpace->palette);
    }

    #[Test]
    public function honoursThePixelLimit(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('more than the 4 allowed');
        (new TiffDecoder())->decode(
            (new TiffFixture(4, 2, 2, 8, 3, str_repeat("\x00", times: 24)))->bytes(),
            new Limits(maxImagePixels: 4),
            new ImageOptions(),
        );
    }

    #[Test]
    public function keepsTheProfileResolutionAndOrientation(): void
    {
        $data = self::decode((new TiffFixture(1, 1, 5, 8, 4, "\x00\x00\x00\xFF"))->withTags([
            274    => [3, [6]],
            282    => [5, [[300, 1]]],
            283    => [5, [[600, 1]]],
            296    => [3, [2]],
            34_675 => [7, 'ICC-PROFILE'],
        ]));

        self::assertSame(['ICC-PROFILE', 300.0, 600.0, 6], [
            $data->raster->iccProfile,
            $data->dpiX,
            $data->dpiY,
            $data->orientation,
        ]);
    }

    #[Test]
    #[DataProvider('unsupportedProvider')]
    public function leavesOtherLayoutsToTheTranscoder(TiffFixture $tiff): void
    {
        self::assertInstanceOf(
            ImageTraits::class,
            (new TiffDecoder())->decode($tiff->bytes(), Limits::default(), new ImageOptions()),
        );
    }

    #[Test]
    public function readsALaterPage(): void
    {
        self::assertSame(
            4,
            self::decode((new TiffFixture(4, 2, 2, 8, 3, str_repeat("\x00", times: 24)))->pages(3), page: 3)
                ->raster
                ->width,
        );
    }

    /** @param array{0: string, 1: int, 2: string} $expected */
    #[Test]
    #[DataProvider('layoutProvider')]
    public function readsTheSamples(TiffFixture $tiff, array $expected): void
    {
        $data = self::decode($tiff);

        self::assertSame($expected, [
            $data->raster->colorSpace->device,
            $data->raster->bitsPerComponent,
            $data->raster->data,
        ]);
    }

    #[Test]
    public function truncatedSamplesAreAnError(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('TIFF image data is truncated.');
        self::decode(new TiffFixture(4, 2, 2, 8, 3, "\x00\x00\x00"));
    }

    #[Test]
    public function unassociatedAlphaBecomesASoftMask(): void
    {
        $data = self::decode((new TiffFixture(2, 1, 2, 8, 4, "\x01\x02\x03\x80\x04\x05\x06\xFF"))->withTags([
            338 => [3, [2]],
        ]));

        self::assertSame(["\x01\x02\x03\x04\x05\x06", "\x80\xFF"], [$data->raster->data, $data->softMask?->data]);
    }

    #[Test]
    public function whiteIsZeroGreyIsInvertedWithADecodeArray(): void
    {
        self::assertSame([1, 0], self::decode(new TiffFixture(2, 1, 0, 8, 1, "\x00\xFF"))->raster->decode);
    }
}
