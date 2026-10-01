<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Dimensions;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2PdfTest\Support\JpegFixtures;
use Php2PdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function function_exists;
use function imagecolorallocate;
use function imagecolorat;
use function imagecreatefrompng;
use function imagecreatetruecolor;
use function imagefilledrectangle;
use function imagejpeg;
use function imagesx;
use function imagesy;
use function ob_get_clean;
use function ob_start;

/** A rendered JPEG appears the way up its EXIF orientation says (#26). */
#[Group('integration')]
final class ImageOrientationTest extends TestCase
{
    /** @return array<string, array{0: int, 1: string, 2: string}> */
    public static function orientationProvider(): array
    {
        return [
            'as stored'          => [1, 'red', 'blue'],
            'mirrored'           => [2, 'blue', 'red'],
            'half turn'          => [3, 'blue', 'red'],
            'quarter turn right' => [6, 'red', 'blue'],
            'quarter turn left'  => [8, 'blue', 'red'],
        ];
    }

    private static function colour(int|false $pixel): string
    {
        $red  = ((int) $pixel >> 16) & 0xFF;
        $blue = (int) $pixel & 0xFF;

        return $red > $blue ? 'red' : 'blue';
    }

    /** A 40 by 20 pixel JPEG, red on the left and blue on the right. */
    private static function halves(): string
    {
        $image = imagecreatetruecolor(
            width: 40,
            height: 20,
        );
        self::assertNotFalse($image);
        $red  = (int) imagecolorallocate($image, red: 255, green: 0, blue: 0);
        $blue = (int) imagecolorallocate($image, red: 0, green: 0, blue: 255);
        imagefilledrectangle($image, x1: 0, y1: 0, x2: 19, y2: 19, color: $red);
        imagefilledrectangle($image, x1: 20, y1: 0, x2: 39, y2: 19, color: $blue);
        ob_start();
        imagejpeg($image, quality: 95);

        return (string) ob_get_clean();
    }

    /**
     * The stored image is red on its left half and blue on its right. A quarter turn shows it
     * upright, so its halves become top and bottom; otherwise they stay left and right.
     */
    #[Test]
    #[DataProvider('orientationProvider')]
    public function showsTheImageInItsOrientation(int $orientation, string $first, string $second): void
    {
        if (! function_exists('imagejpeg')) {
            self::markTestSkipped('Building the test JPEG needs ext-gd.');
        }
        $path = PdfTools::outputDir() . "/orientation-{$orientation}.pdf";
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->addPage(new Dimensions(100.0, 100.0))->place(
            $doc->imageFromData(JpegFixtures::withExif(self::halves(), $orientation)),
            new Box(0.0, 0.0, 100.0, 100.0),
            Fit::entire(),
        );
        $doc->close();

        $png = imagecreatefrompng(PdfTools::render($path, dpi: 72));
        self::assertNotFalse($png);
        [$w, $h] = [imagesx($png), imagesy($png)];
        $turned = $orientation >= 5;
        $a      = $turned ? [$w / 2, $h / 4] : [$w / 4, $h / 2];
        $b      = $turned ? [$w / 2, (3 * $h) / 4] : [(3 * $w) / 4, $h / 2];

        self::assertSame(
            [$first, $second],
            [
                self::colour(imagecolorat($png, (int) $a[0], (int) $a[1])),
                self::colour(imagecolorat($png, (int) $b[0], (int) $b[1])),
            ],
        );
    }
}
