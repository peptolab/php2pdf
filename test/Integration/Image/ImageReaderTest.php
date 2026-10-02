<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Image;

use Php2Pdf\Core\Filter\Flate;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Image\ImageData;
use Php2Pdf\Image\ImageReader;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function abs;
use function chr;
use function extension_loaded;
use function imagealphablending;
use function imagecolorallocatealpha;
use function imagecreatetruecolor;
use function imageinterlace;
use function imagepng;
use function imagesavealpha;
use function imagesetpixel;
use function intdiv;
use function ob_get_clean;
use function ob_start;
use function ord;
use function str_repeat;
use function strlen;
use function substr;

#[Group('integration')]
final class ImageReaderTest extends TestCase
{
    /** @return array<string, array{0: int, 1: int}> */
    public static function interlaceSizeProvider(): array
    {
        return [
            'one pixel'        => [1, 1],
            'three by two'     => [3, 2],
            'odd width'        => [97, 61],
            'taller than wide' => [9, 40],
        ];
    }

    /** @return iterable<string, array{0: string, 1: string, 2: int, 3: int, 4: string, 5: string}> */
    public static function pngs(): iterable
    {
        // file, device, bits, components, transparency, encoding
        yield 'rgb8' => ['rgb8.png', 'DeviceRGB', 8, 3, 'opaque', 'passthrough'];
        yield 'rgb16' => ['rgb16.png', 'DeviceRGB', 16, 3, 'opaque', 'passthrough'];
        yield 'gray8' => ['gray8.png', 'DeviceGray', 8, 1, 'opaque', 'passthrough'];
        yield 'gray4' => ['gray4.png', 'DeviceGray', 4, 1, 'opaque', 'passthrough'];
        yield 'gray1' => ['gray1.png', 'DeviceGray', 1, 1, 'opaque', 'passthrough'];
        yield 'indexed' => ['indexed.png', 'DeviceRGB', 4, 1, 'opaque', 'passthrough'];
        yield 'rgba8' => ['rgba8.png', 'DeviceRGB', 8, 3, 'smask', 'decoded'];
        yield 'gray-alpha' => ['gray-alpha.png', 'DeviceGray', 8, 1, 'smask', 'decoded'];
        yield 'indexed-trns' => ['indexed-trns.png', 'DeviceRGB', 8, 1, 'smask', 'decoded'];
        yield 'interlaced-rgb' => ['interlaced-rgb.png', 'DeviceRGB', 8, 3, 'opaque', 'decoded'];
        yield 'interlaced-rgba' => ['interlaced-rgba.png', 'DeviceRGB', 8, 3, 'smask', 'decoded'];
        yield 'interlaced-gray1' => ['interlaced-gray1.png', 'DeviceGray', 8, 1, 'opaque', 'decoded'];
    }

    private static function read(string $name): ImageData
    {
        return ImageReader::readFile(PdfTools::assetPath("images/{$name}"));
    }

    /**
     * Reverses PNG filters with a tiny independent implementation, for cross-checking.
     *
     * @param non-negative-int $width
     * @param non-negative-int $bpp
     */
    private static function unfilterReference(string $raw, int $width, int $height, int $bpp): string
    {
        $rowBytes = $width * $bpp;
        $out      = '';
        $prev     = str_repeat("\0", $rowBytes);
        for ($y = 0; $y < $height; ++$y) {
            $filter = ord($raw[$y * ($rowBytes + 1)]);
            $row    = substr($raw, ($y * ($rowBytes + 1)) + 1, $rowBytes);
            $line   = '';
            for ($i = 0; $i < $rowBytes; ++$i) {
                $x = ord($row[$i]);
                $a = $i >= $bpp ? ord($line[$i - $bpp]) : 0;
                $b = ord($prev[$i]);
                $c = $i >= $bpp ? ord($prev[$i - $bpp]) : 0;
                $p = $a + $b - $c;
                [$pa, $pb, $pc] = [abs($p - $a), abs($p - $b), abs($p - $c)];
                $paeth = $pb <= $pc ? $b : $c;
                if ($pa <= $pb && $pa <= $pc) {
                    $paeth = $a;
                }
                $predictor = match ($filter) {
                    0       => 0,
                    1       => $a,
                    2       => $b,
                    3       => ($a + $b) >> 1,
                    default => $paeth,
                };
                $line .= chr(($x + $predictor) & 0xFF);
            }
            $out  .= $line;
            $prev = $line;
        }

        return $out;
    }

    #[Test]
    public function decodedPixelsMatchPassthroughPixels(): void
    {
        // interlaced-rgb.png holds the same pixels as rgb8.png; decoding must reproduce them.
        $plain      = self::read('rgb8.png');
        $interlaced = self::read('interlaced-rgb.png');
        $decoded    = Flate::decode($interlaced->raster->data);
        $reference  = self::unfilterReference(Flate::decode($plain->raster->data), 64, 48, 3);

        self::assertSame($reference, $decoded);
    }

    #[Test]
    #[DataProvider('interlaceSizeProvider')]
    public function interlacedImagesDecodeLikeTheirNonInterlacedTwins(int $width, int $height): void
    {
        if (! extension_loaded('gd')) {
            self::markTestSkipped('ext-gd is needed to write interlaced test images.');
        }
        $image = imagecreatetruecolor($width, $height);
        self::assertNotFalse($image);
        imagealphablending($image, enable: false);
        imagesavealpha($image, enable: true);
        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $color = imagecolorallocatealpha(
                    $image,
                    ($x * 7) % 256,
                    ($y * 11) % 256,
                    ($x + $y) % 256,
                    ($x + $y) % 128,
                );
                imagesetpixel($image, $x, $y, (int) $color);
            }
        }
        $png = static function (bool $interlace) use ($image): string {
            imageinterlace($image, $interlace);
            ob_start();
            imagepng($image);

            return (string) ob_get_clean();
        };
        $interlaced = ImageReader::read($png(true));
        $plain      = ImageReader::read($png(false));

        self::assertSame(
            [$plain->raster->data, $plain->softMask?->data],
            [$interlaced->raster->data, $interlaced->softMask?->data],
        );
    }

    #[Test]
    public function readsColorKeyAndResolution(): void
    {
        $key = self::read('colorkey.png');
        self::assertNotNull($key->raster->colorKeyMask);
        self::assertCount(6, $key->raster->colorKeyMask);
        self::assertTrue($key->hasTransparency());

        $dpi = self::read('rgb-150dpi.png');
        self::assertEqualsWithDelta(150.0, $dpi->dpiX, 0.1);
    }

    #[Test]
    public function readsJpegHeaders(): void
    {
        $rgb = self::read('rgb.jpg');
        self::assertSame([64, 48, 8, 'DeviceRGB', 'DCTDecode'], [
            $rgb->raster->width,
            $rgb->raster->height,
            $rgb->raster->bitsPerComponent,
            $rgb->raster->colorSpace->device,
            $rgb->raster->filter,
        ]);
        self::assertNull($rgb->raster->decode);
        self::assertSame(72.0, $rgb->dpiX);

        $gray = self::read('gray.jpg');
        self::assertSame(1, $gray->raster->colorSpace->components);

        $cmyk = self::read('cmyk.jpg');
        self::assertSame('DeviceCMYK', $cmyk->raster->colorSpace->device);
        self::assertSame([1, 0, 1, 0, 1, 0, 1, 0], $cmyk->raster->decode, 'Adobe CMYK JPEGs are inverted.');

        self::assertSame(64, self::read('progressive.jpg')->raster->width);
        self::assertSame(150.0, self::read('rgb-150dpi.jpg')->dpiX);
    }

    #[Test]
    #[DataProvider('pngs')]
    public function readsPngVariants(
        string $file,
        string $device,
        int $bits,
        int $components,
        string $transparency,
        string $encoding,
    ): void {
        $image       = self::read($file);
        $softMask    = 'smask' === $transparency;
        $passthrough = 'passthrough' === $encoding;

        self::assertSame([64, 48], [$image->raster->width, $image->raster->height]);
        self::assertSame($device, $image->raster->colorSpace->device);
        self::assertSame($bits, $image->raster->bitsPerComponent);
        self::assertSame($components, $image->raster->colorSpace->components);
        self::assertSame('FlateDecode', $image->raster->filter);
        self::assertSame($softMask, null !== $image->softMask);
        self::assertSame($passthrough, null !== $image->raster->decodeParms);
        if ($softMask) {
            self::assertSame(64 * 48, strlen($image->softMask->data ?? ''));
        }
        // Decoded data has exactly the expected number of samples.
        $expectedBytes = 64 * 48 * $components * intdiv($bits, num2: 8);
        if (! $passthrough) {
            self::assertSame($expectedBytes, strlen(Flate::decode($image->raster->data)));
        }
    }

    #[Test]
    public function rejectsGarbage(): void
    {
        $this->expectException(PdfException::class);
        ImageReader::read("\xFF\xD8\xFF\xE0garbage");
    }
}
