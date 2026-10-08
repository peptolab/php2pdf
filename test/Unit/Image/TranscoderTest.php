<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Image;

use Imagick;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Image\ImageOptions;
use Php2Pdf\Image\ImageReader;
use Php2Pdf\Image\Transcoder;
use Php2Pdf\Image\TranscoderExtensions;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function imagecolorallocate;
use function imagecreatetruecolor;
use function imagegif;
use function ob_get_clean;
use function ob_start;
use function substr;

/**
 * Formats no decoder reads are converted to PNG through ext-imagick, keeping deep samples and
 * a grey image's alpha channel, or else through ext-gd.
 */
#[Group('unit')]
final class TranscoderTest extends TestCase
{
    private static function gdGif(): string
    {
        $image = imagecreatetruecolor(
            width: 1,
            height: 1,
        );
        imagecolorallocate($image, red: 255, green: 0, blue: 0);
        ob_start();
        imagegif($image);

        return (string) ob_get_clean();
    }

    private static function gdOnly(): TranscoderExtensions
    {
        return new TranscoderExtensions(
            imagick: false,
            gd: true,
        );
    }

    private static function twoPageGif(): string
    {
        $gif = new Imagick();
        foreach (['red', 'blue'] as $colour) {
            $frame = new Imagick();
            $frame->newImage(
                columns: 1,
                rows: 1,
                background_color: $colour,
            );
            $frame->setImageFormat('gif');
            $gif->addImage($frame);
        }

        return $gif->getImagesBlob();
    }

    #[Test]
    #[RequiresPhpExtension('imagick')]
    public function aGreyImageKeepsItsAlphaChannel(): void
    {
        $pam   = "P7\nWIDTH 1\nHEIGHT 1\nDEPTH 2\nMAXVAL 255\nTUPLTYPE GRAYSCALE_ALPHA\nENDHDR\n\x80\x40";
        $image = ImageReader::read($pam);

        self::assertSame(['DeviceGray', true], [$image->raster->colorSpace->device, null !== $image->softMask]);
    }

    #[Test]
    #[RequiresPhpExtension('imagick')]
    public function aPagePastTheLastIsRefused(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('The image has 2 pages; page 3 was asked for.');
        ImageReader::read(self::twoPageGif(), options: new ImageOptions(page: 3));
    }

    #[Test]
    #[RequiresPhpExtension('gd')]
    public function gdConvertsWhenImagickIsMissing(): void
    {
        self::assertSame("\x89PNG", substr(
            Transcoder::toPng(self::gdGif(), extensions: self::gdOnly()),
            offset: 0,
            length: 4,
        ));
    }

    #[Test]
    #[RequiresPhpExtension('gd')]
    public function gdRefusesWhatItCannotRead(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('ext-gd could not read the image.');
        Transcoder::toPng('not an image', extensions: self::gdOnly());
    }

    #[Test]
    #[RequiresPhpExtension('imagick')]
    public function isAvailableWithImagickLoaded(): void
    {
        self::assertTrue(Transcoder::isAvailable());
    }

    #[Test]
    public function laterPagesNeedImagick(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Reading page 2 of a multi-page image needs ext-imagick.');
        Transcoder::toPng('GIF89a', page: 2, extensions: self::gdOnly());
    }

    #[Test]
    #[RequiresPhpExtension('imagick')]
    public function sixteenBitSamplesAreKept(): void
    {
        $ppm = "P6\n1 1\n65535\n\x12\x34\x56\x78\x9A\xBC";

        self::assertSame(16, ImageReader::read($ppm)->raster->bitsPerComponent);
    }

    #[Test]
    public function withNeitherExtensionOnlyJpegAndPngAreRead(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage(
            'Only JPEG and PNG are read natively; install ext-imagick or ext-gd for other formats.',
        );
        Transcoder::toPng('GIF89a', extensions: new TranscoderExtensions(
            imagick: false,
            gd: false,
        ));
    }
}
