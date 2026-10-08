<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Image;

use Imagick;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Image\ImageOptions;
use Php2Pdf\Image\ImageReader;
use Php2Pdf\Image\Transcoder;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Formats no decoder reads are converted to PNG through ext-imagick, keeping deep samples and
 * a grey image's alpha channel.
 */
#[Group('unit')]
#[RequiresPhpExtension('imagick')]
final class TranscoderTest extends TestCase
{
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
    public function aGreyImageKeepsItsAlphaChannel(): void
    {
        $pam   = "P7\nWIDTH 1\nHEIGHT 1\nDEPTH 2\nMAXVAL 255\nTUPLTYPE GRAYSCALE_ALPHA\nENDHDR\n\x80\x40";
        $image = ImageReader::read($pam);

        self::assertSame(['DeviceGray', true], [$image->raster->colorSpace->device, null !== $image->softMask]);
    }

    #[Test]
    public function aPagePastTheLastIsRefused(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('The image has 2 pages; page 3 was asked for.');
        ImageReader::read(self::twoPageGif(), options: new ImageOptions(page: 3));
    }

    #[Test]
    public function isAvailableWithImagickLoaded(): void
    {
        self::assertTrue(Transcoder::isAvailable());
    }

    #[Test]
    public function sixteenBitSamplesAreKept(): void
    {
        $ppm = "P6\n1 1\n65535\n\x12\x34\x56\x78\x9A\xBC";

        self::assertSame(16, ImageReader::read($ppm)->raster->bitsPerComponent);
    }
}
