<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Image;

use Php2Pdf\Core\Limits;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Image\ImageData;
use Php2Pdf\Image\ImageOptions;
use Php2Pdf\Image\JpxDecoder;
use Php2PdfTest\TestAsset\Image\Jp2Boxes;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function pack;

/** A JP2 file's size, components and colour space are read from its header boxes. */
#[Group('unit')]
final class JpxDecoderTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function channelDefinitionProvider(): array
    {
        return [
            'too short for its channels' => [pack('n', 1)],
            'colour channels only'       => [pack('nnnn', 1, 0, 0, 1)],
        ];
    }

    private static function decode(string $data): ImageData
    {
        return (new JpxDecoder())->decode($data, Limits::default(), new ImageOptions());
    }

    #[Test]
    public function aBoxWithAnExtendedLengthIsRead(): void
    {
        $image = self::decode(Jp2Boxes::file(Jp2Boxes::extendedBox(
            'jp2h',
            Jp2Boxes::imageHeader(
                width: 5,
                height: 3,
                components: 1,
            ),
        )));

        self::assertSame([5, 3], [$image->raster->width, $image->raster->height]);
    }

    #[Test]
    #[DataProvider('channelDefinitionProvider')]
    public function aChannelDefinitionWithoutOpacityGivesNoAlpha(string $cdef): void
    {
        $image = self::decode(Jp2Boxes::file(Jp2Boxes::header(5, 3, 3, Jp2Boxes::box('cdef', $cdef))));

        self::assertFalse($image->raster->smaskInData);
    }

    #[Test]
    public function anImageWithoutPixelsIsRefused(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('JPEG 2000 image has no pixels.');

        self::decode(Jp2Boxes::file(Jp2Boxes::header(
            width: 0,
            height: 3,
            components: 1,
        )));
    }

    #[Test]
    public function anUnsupportedComponentCountIsRefused(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('JPEG 2000 images with 2 components are not supported.');

        self::decode(Jp2Boxes::file(Jp2Boxes::header(
            width: 5,
            height: 3,
            components: 2,
        )));
    }

    #[Test]
    public function dataThatIsNotAJpeg2000FileIsRefused(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Not a JPEG 2000 file.');

        self::decode('GIF89a');
    }

    #[Test]
    public function fourComponentsAreCmyk(): void
    {
        $image = self::decode(Jp2Boxes::file(Jp2Boxes::header(
            width: 5,
            height: 3,
            components: 4,
        )));

        self::assertSame('DeviceCMYK', $image->raster->colorSpace->device);
    }
}
