<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Image;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Image\ImageReader;
use Php2PdfTest\TestAsset\JpegFixtures;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function file_get_contents;
use function substr_replace;

/** JPEGs are shown in their EXIF orientation and at their EXIF resolution (#26). */
#[Group('integration')]
final class ExifTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function malformedProvider(): array
    {
        $exif = JpegFixtures::withExif(self::jpeg(), 6);

        return [
            'orientation out of range' => [JpegFixtures::withExif(self::jpeg(), 9)],
            'unknown byte order'       => [substr_replace($exif, replace: 'XX', offset: 12, length: 2)],
            'directory past the end'   => [substr_replace($exif, replace: "\xFF\xFF\x00\x00", offset: 16, length: 4)],
        ];
    }

    /** @return array<string, array{0: int, 1: 'II'|'MM', 2: string}> */
    public static function orientationProvider(): array
    {
        $cases = [];
        foreach (['II' => 'Intel', 'MM' => 'Motorola'] as $byteOrder => $order) {
            foreach ([
                1 => '64 0 0 48 0 0',
                2 => '-64 0 0 48 64 0',
                3 => '-64 0 0 -48 64 48',
                4 => '64 0 0 -48 0 48',
                5 => '0 -64 -48 0 48 64',
                6 => '0 -64 48 0 0 64',
                7 => '0 64 48 0 0 0',
                8 => '0 64 -48 0 48 0',
            ] as $orientation => $matrix) {
                $cases["{$orientation}, {$order} byte order"] = [$orientation, $byteOrder, $matrix];
            }
        }

        return $cases;
    }

    /** @return array<string, array{0: int, 1: 'II'|'MM'}> */
    public static function orientationReadProvider(): array
    {
        return array_map(
            /**
             * @param array{0: int, 1: 'II'|'MM', 2: string} $case
             * @return array{0: int, 1: 'II'|'MM'}
             */
            static fn(array $case): array => [$case[0], $case[1]],
            self::orientationProvider(),
        );
    }

    /** @return array<string, array{0: array{0: int, 1: int, 2: int}, 1: float}> */
    public static function resolutionProvider(): array
    {
        return [
            'inches'      => [[300, 300, 2], 300.0],
            'centimetres' => [[118, 118, 3], 118 * 2.54],
            'no unit'     => [[300, 300, 1], 72.0],
        ];
    }

    private static function jpeg(): string
    {
        return (string) file_get_contents(PdfTools::assetPath('images/rgb.jpg'));
    }

    #[Test]
    public function aJfifDensityWinsOverExif(): void
    {
        $jfif = substr_replace(self::jpeg(), replace: "\x01\x00\xC8\x00\xC8", offset: 13, length: 5);
        $data = ImageReader::read(JpegFixtures::withExif($jfif, 1, [300, 300, 2]));

        self::assertSame([200.0, 200.0], [$data->dpiX, $data->dpiY]);
    }

    #[Test]
    public function aTransposingOrientationSwapsTheNaturalSize(): void
    {
        $doc   = new Document(new MemoryOutput());
        $image = $doc->imageFromData(JpegFixtures::withExif(self::jpeg(), 6, [144, 144, 2]));

        self::assertSame([24.0, 32.0], [$image->naturalSize()->width, $image->naturalSize()->height]);
    }

    /**
     * At 72 dpi a 64 by 48 pixel image is drawn into a 64 by 48 pt box, turned 48 by 64 when transposed.
     *
     * @param 'II'|'MM' $byteOrder
     */
    #[Test]
    #[DataProvider('orientationProvider')]
    public function drawsTheImageInItsOrientation(int $orientation, string $byteOrder, string $matrix): void
    {
        $out   = new MemoryOutput();
        $doc   = new Document($out, new DocumentOptions(compress: false));
        $image = $doc->imageFromData(JpegFixtures::withExif(self::jpeg(), $orientation, byteOrder: $byteOrder));
        $size  = $image->naturalSize();
        $doc->addPage(PageSize::A4)->place($image, new Box(0.0, 0.0, $size->width, $size->height), Fit::entire());
        $doc->close();

        self::assertStringContainsString("q {$matrix} cm /", $out->contents());
    }

    #[Test]
    #[DataProvider('malformedProvider')]
    public function malformedExifIsIgnored(string $jpeg): void
    {
        self::assertSame(1, ImageReader::read($jpeg)->orientation);
    }

    /** @param 'II'|'MM' $byteOrder */
    #[Test]
    #[DataProvider('orientationReadProvider')]
    public function readsTheOrientationInEitherByteOrder(int $orientation, string $byteOrder): void
    {
        $data = ImageReader::read(JpegFixtures::withExif(self::jpeg(), $orientation, byteOrder: $byteOrder));

        self::assertSame($orientation, $data->orientation);
    }

    /** @param array{0: int, 1: int, 2: int} $resolution */
    #[Test]
    #[DataProvider('resolutionProvider')]
    public function usesTheExifResolutionWhenJfifGivesNone(array $resolution, float $dpi): void
    {
        $data = ImageReader::read(JpegFixtures::withExif(self::jpeg(), 1, $resolution));

        self::assertEqualsWithDelta([$dpi, $dpi], [$data->dpiX, $data->dpiY], 1.0e-9);
    }
}
