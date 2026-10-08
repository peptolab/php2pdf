<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Image;

use Php2Pdf\Core\Limits;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Image\ImageData;
use Php2Pdf\Image\ImageOptions;
use Php2Pdf\Image\JpegDecoder;
use Php2PdfTest\TestAsset\Image\JpegSegments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function pack;

/** The frame header and metadata of a JPEG are read from its marker segments. */
#[Group('unit')]
final class JpegDecoderTest extends TestCase
{
    /** @return array<string, array{0: string, 1: array{0: float, 1: float}}> */
    public static function jfifDensityProvider(): array
    {
        return [
            'dots per centimetre'         => [
                "JFIF\0\x01\x02" . pack('Cnn', 2, 100, 200) . "\0\0",
                [100 * 2.54, 200 * 2.54],
            ],
            'too short to give a density' => ["JFIF\0\x01\x02", [72.0, 72.0]],
        ];
    }

    private static function decode(string $data): ImageData
    {
        return (new JpegDecoder())->decode($data, Limits::default(), new ImageOptions());
    }

    #[Test]
    public function aFrameHeaderTooShortIsRefused(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Truncated JPEG frame header.');

        self::decode(JpegSegments::file(JpegSegments::segment(0xC0, "\x08\x00")));
    }

    #[Test]
    public function anIccProfileInSeveralChunksIsJoinedInSequenceOrder(): void
    {
        $image = self::decode(JpegSegments::file(
            JpegSegments::segment(0xE2, "ICC_PROFILE\0\x02\x02two"),
            JpegSegments::segment(0xE2, "ICC_PROFILE\0\x01\x02one"),
            JpegSegments::frame(
                width: 3,
                height: 2,
            ),
        ));

        self::assertSame('onetwo', $image->raster->iccProfile);
    }

    #[Test]
    public function dataThatIsNotAJpegIsRefused(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Not a JPEG file.');

        self::decode('GIF89a');
    }

    #[Test]
    public function fillBytesStrayBytesAndMarkersWithoutALengthAreSkipped(): void
    {
        $image = self::decode(JpegSegments::file(
            "\x00\xFF\xFF\xD0\xFF\x01",
            JpegSegments::segment(0xFE, 'a comment'),
            JpegSegments::frame(
                width: 3,
                height: 2,
            ),
        ));

        self::assertSame([3, 2], [$image->raster->width, $image->raster->height]);
    }

    /** @param array{0: float, 1: float} $expected */
    #[Test]
    #[DataProvider('jfifDensityProvider')]
    public function theJfifHeaderGivesTheResolution(string $jfif, array $expected): void
    {
        $image = self::decode(JpegSegments::file(
            JpegSegments::segment(0xE0, $jfif),
            JpegSegments::frame(
                width: 3,
                height: 2,
            ),
        ));

        self::assertSame($expected, [$image->dpiX, $image->dpiY]);
    }
}
