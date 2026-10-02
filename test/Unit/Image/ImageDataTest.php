<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Image;

use Php2Pdf\Image\ImageColorSpace;
use Php2Pdf\Image\ImageData;
use Php2Pdf\Image\Raster;
use Php2Pdf\Image\SoftMask;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ImageDataTest extends TestCase
{
    #[Test]
    public function anImageWithoutAProfileOrMaskStaysWithoutThem(): void
    {
        $bare = (new ImageData(new Raster(1, 1, 8, ImageColorSpace::gray(), "\x00")))->withoutSamples();

        self::assertSame([null, null, false], [$bare->raster->iccProfile, $bare->softMask, $bare->hasTransparency()]);
    }

    #[Test]
    public function withoutSamplesKeepsTheDescriptionAndDropsTheBytes(): void
    {
        $image = new ImageData(
            new Raster(2, 1, 16, ImageColorSpace::rgb(), '0123456789ab', 'FlateDecode', iccProfile: 'PROFILE'),
            new SoftMask('mask', 16),
            dpiX: 300.0,
            dpiY: 150.0,
            orientation: 6,
        );
        $bare = $image->withoutSamples();

        self::assertSame(
            [2, 1, 16, 'FlateDecode', '', '', '', 16, 300.0, 150.0, 6, true],
            [
                $bare->raster->width,
                $bare->raster->height,
                $bare->raster->bitsPerComponent,
                $bare->raster->filter,
                $bare->raster->data,
                $bare->raster->iccProfile,
                $bare->softMask?->data,
                $bare->softMask?->bits,
                $bare->dpiX,
                $bare->dpiY,
                $bare->orientation,
                $bare->hasTransparency(),
            ],
        );
    }
}
