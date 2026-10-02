<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Image;

use Php2Pdf\Core\Limits;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Image\ImageColorSpace;
use Php2Pdf\Image\ImageData;
use Php2Pdf\Image\ImageDecoders;
use Php2Pdf\Image\ImageOptions;
use Php2Pdf\Image\ImageTraits;
use Php2Pdf\Image\JpegDecoder;
use Php2Pdf\Image\JpxDecoder;
use Php2Pdf\Image\PngDecoder;
use Php2Pdf\Image\TiffDecoder;
use Php2PdfTest\TestAsset\FakeImageDecoder;
use Php2PdfTest\TestAsset\TiffFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ImageDecodersTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function singlePageProvider(): array
    {
        return [
            'JPEG'                 => ["\xFF\xD8", 'JPEG'],
            'JPEG 2000'            => [JpxDecoder::JP2_SIGNATURE, 'JPEG 2000'],
            'JPEG 2000 codestream' => [JpxDecoder::CODESTREAM, 'JPEG 2000'],
            'PNG'                  => ["\x89PNG\r\n\x1A\n", 'PNG'],
        ];
    }

    private static function image(): ImageData
    {
        return new ImageData(1, 1, 8, ImageColorSpace::gray(), "\x00");
    }

    #[Test]
    public function aDecoderReadsTheFormatsItAccepts(): void
    {
        $image = self::image();

        self::assertSame(
            $image,
            (new ImageDecoders([new FakeImageDecoder($image)]))->read('FAKE', Limits::default(), new ImageOptions()),
        );
    }

    #[Test]
    #[DataProvider('singlePageProvider')]
    public function aFormatWithOnePageRefusesALaterPage(string $data, string $name): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage("{$name} files have one page; page 2 was asked for.");

        ImageDecoders::default()->read($data, Limits::default(), new ImageOptions(page: 2));
    }

    #[Test]
    public function aFormatWithPagesIsAskedForALaterPage(): void
    {
        $image = self::image();

        self::assertSame(
            $image,
            (new ImageDecoders([new FakeImageDecoder($image, pages: true)]))->read(
                'FAKE',
                Limits::default(),
                new ImageOptions(page: 2),
            ),
        );
    }

    #[Test]
    public function aLayoutTheDecoderDoesNotReadIsNotConvertedWhenDataWouldBeLost(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage(
            'Image file "scan.fake" is not read natively, and converting it to PNG would lose data: it is CMYK',
        );

        (new ImageDecoders([new FakeImageDecoder(new ImageTraits('CMYK'))]))->read(
            'FAKE',
            Limits::default(),
            new ImageOptions(),
            'scan.fake',
        );
    }

    #[Test]
    public function aTiffIsReadFromTheRequestedPage(): void
    {
        $tiff = (new TiffFixture(1, 1, 1, 8, 1, "\x7F"))->pages(2)
            ->bytes();

        self::assertSame(
            "\x7F",
            ImageDecoders::default()->read($tiff, Limits::default(), new ImageOptions(page: 2))->data,
        );
    }

    #[Test]
    public function everyDecoderNamesItsFormat(): void
    {
        self::assertSame(
            ['JPEG', 'JPEG 2000', 'PNG', 'TIFF'],
            [
                (new JpegDecoder())->name(),
                (new JpxDecoder())->name(),
                (new PngDecoder())->name(),
                (new TiffDecoder())->name(),
            ],
        );
    }
}
