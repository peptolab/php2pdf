<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Image;

use Php2Pdf\Exception\ParseException;
use Php2Pdf\Image\ImageOptions;
use Php2Pdf\Image\ImageReader;
use Php2Pdf\Image\ImageTraits;
use Php2PdfTest\TestAsset\TiffFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function extension_loaded;
use function imagecolorallocate;
use function imagecreate;
use function imagegif;
use function ob_get_clean;
use function ob_start;
use function str_repeat;

/**
 * Files that are not read natively are converted to PNG only when nothing is lost; otherwise the
 * conversion is refused unless ImageOptions::$allowLossyConversion accepts it.
 */
#[Group('unit')]
final class LossyConversionTest extends TestCase
{
    /** @return array<string, array{0: TiffFixture, 1: string}> */
    public static function lossyTiffProvider(): array
    {
        $tiled = [322 => [3, [16]]];

        return [
            'tiled CMYK'                         => [
                (new TiffFixture(2, 1, 5, 8, 4, str_repeat("\x00", times: 8)))->withTags($tiled),
                'it is CMYK and would become RGB',
            ],
            'multi-ink separation in planes'     => [
                (new TiffFixture(2, 1, 5, 8, 4, str_repeat("\x00", times: 8)))->separatePlanes()
                    ->withTags([332 => [3, [2]]]),
                'it is a multi-ink separation and would become RGB',
            ],
            'tiled CIELab'                       => [
                (new TiffFixture(2, 1, 8, 8, 3, str_repeat("\x00", times: 6)))->withTags($tiled),
                'it is CIELab and would become RGB',
            ],
            'RGB in planes with an ICC profile'  => [
                (new TiffFixture(2, 1, 2, 8, 3, str_repeat("\x00", times: 6)))->separatePlanes()
                    ->withTags([34_675 => [7, 'ICC-PROFILE']]),
                'its ICC profile would not be kept',
            ],
            '32-bit grey'                        => [
                new TiffFixture(1, 1, 1, 32, 1, "\x00\x00\x00\x00"),
                'its 32-bit samples would be cut to 16 bits',
            ],
            'tiled CMYK with a profile, at once' => [
                (new TiffFixture(2, 1, 5, 8, 4, str_repeat("\x00", times: 8)))->withTags([
                    322    => [3, [16]],
                    34_675 => [7, 'ICC-PROFILE'],
                ]),
                'it is CMYK and would become RGB; its ICC profile would not be kept.',
            ],
        ];
    }

    /** @return array<string, array{0: ImageTraits, 1: ImageTraits, 2: list<string>}> */
    public static function traitsProvider(): array
    {
        return [
            '8-bit RGB into an 8-bit PNG'        => [new ImageTraits(bits: 8), new ImageTraits(bits: 8), []],
            '1-bit grey into an 8-bit PNG'       => [new ImageTraits(bits: 1), new ImageTraits(bits: 8), []],
            '16-bit into a 16-bit PNG'           => [new ImageTraits(bits: 16), new ImageTraits(bits: 16), []],
            '16-bit into an 8-bit PNG'           => [
                new ImageTraits(bits: 16),
                new ImageTraits(bits: 8),
                ['its 16-bit samples would be cut to 8 bits'],
            ],
            'alpha into a PNG without'           => [
                new ImageTraits(
                    bits: 8,
                    alpha: true,
                ),
                new ImageTraits(bits: 8),
                ['its alpha channel would be lost'],
            ],
            'alpha into a PNG with'              => [
                new ImageTraits(
                    bits: 8,
                    alpha: true,
                ),
                new ImageTraits(
                    bits: 8,
                    alpha: true,
                ),
                [],
            ],
            'a profile into a PNG that keeps it' => [
                new ImageTraits(
                    iccProfile: true,
                    bits: 8,
                ),
                new ImageTraits(
                    iccProfile: true,
                    bits: 8,
                ),
                [],
            ],
        ];
    }

    #[Test]
    public function aLosslessConversionIsNotRefused(): void
    {
        if (! extension_loaded('gd')) {
            self::markTestSkipped('ext-gd is needed to write the GIF.');
        }
        $gif = imagecreate(
            width: 3,
            height: 2,
        );
        self::assertNotFalse($gif);
        imagecolorallocate($gif, red: 200, green: 30, blue: 30);
        ob_start();
        imagegif($gif);
        $image = ImageReader::read((string) ob_get_clean());

        self::assertSame([3, 2], [$image->width, $image->height]);
    }

    #[Test]
    public function aLossyConversionIsAcceptedWhenAskedFor(): void
    {
        if (! extension_loaded('imagick')) {
            self::markTestSkipped('Converting a CMYK TIFF the decoder does not read needs ext-imagick.');
        }
        $tiff = (new TiffFixture(2, 1, 5, 8, 4, "\x00\xFF\xFF\x00\x00\x00\x00\x00"))->separatePlanes()
            ->withTags([34_675 => [7, 'ICC-PROFILE']])
            ->bytes();
        $image = ImageReader::read($tiff, options: new ImageOptions(allowLossyConversion: true));

        self::assertSame(['DeviceRGB', null], [$image->colorSpace->device, $image->iccProfile]);
    }

    #[Test]
    #[DataProvider('lossyTiffProvider')]
    public function aLossyConversionIsRefusedByDefault(TiffFixture $tiff, string $reason): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage(
            "The image is not read natively, and converting it to PNG would lose data: {$reason}",
        );
        ImageReader::read($tiff->bytes());
    }

    #[Test]
    public function aWebpWithAnIccProfileIsSniffed(): void
    {
        $webp = "RIFF\x00\x00\x00\x00WEBPVP8X\x0A\x00\x00\x00\x20\x00\x00\x00";

        self::assertTrue(ImageTraits::sniff($webp, size: false)->iccProfile);
    }

    /** @param list<string> $losses */
    #[Test]
    #[DataProvider('traitsProvider')]
    public function onlyWhatTheResultCannotHoldIsALoss(ImageTraits $source, ImageTraits $result, array $losses): void
    {
        self::assertSame($losses, $source->lostIn($result));
    }

    #[Test]
    public function theRefusalNamesTheOptInAndTheFile(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage(
            'Image file "scan.tif" is not read natively, and converting it to PNG would lose data: it is CMYK '
                . 'and would become RGB. Load it with new ImageOptions(allowLossyConversion: true) to accept the '
                . 'conversion, or convert it yourself.',
        );
        ImageReader::read(
            (new TiffFixture(2, 1, 5, 8, 4, str_repeat("\x00", times: 8)))->withTags([322 => [3, [16]]])->bytes(),
            name: 'scan.tif',
        );
    }
}
