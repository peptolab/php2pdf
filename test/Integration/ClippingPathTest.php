<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Dimensions;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Image\ImageOptions;
use Php2PdfTest\TestAsset\PdfTools;
use Php2PdfTest\TestAsset\PhotoshopPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function imagecolorat;
use function imagecreatefrompng;
use function imagesx;
use function imagesy;

/** A Photoshop clipping path cuts the image to its shape in every renderer (#26). */
#[Group('integration')]
final class ClippingPathTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function rendererProvider(): array
    {
        return ['poppler' => ['poppler'], 'Ghostscript' => ['ghostscript'], 'CoreGraphics' => ['coregraphics']];
    }

    /** Clipped to a diamond, the image leaves the page's corners unpainted and fills its centre. */
    #[Test]
    #[DataProvider('rendererProvider')]
    public function theImageTakesThePathsShape(string $renderer): void
    {
        $path = PdfTools::outputDir() . '/clipping-path.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $jpeg = PhotoshopPaths::jpeg(
            (string) file_get_contents(PdfTools::assetPath('images/rgb.jpg')),
            PhotoshopPaths::blocks(['Outline' => PhotoshopPaths::DIAMOND], 'Outline'),
        );
        $doc->addPage(new Dimensions(64.0, 48.0))->place(
            $doc->imageFromData($jpeg, new ImageOptions(clippingPath: true)),
            new Box(0.0, 0.0, 64.0, 48.0),
            Fit::entire(),
        );
        $doc->close();
        PdfTools::assertValid($path);

        $png = imagecreatefrompng(match ($renderer) {
            'ghostscript'  => PdfTools::ghostscriptRender($path, dpi: 144),
            'coregraphics' => PdfTools::coreGraphicsRender($path),
            default        => PdfTools::render($path, dpi: 144),
        });
        self::assertNotFalse($png);
        [$w, $h] = [imagesx($png), imagesy($png)];
        $white = static function (int $x, int $y) use ($png): bool {
            $pixel = (int) imagecolorat($png, $x, $y);

            return ($pixel >> 24) >= 64 || 0xFF_FF_FF === ($pixel & 0xFF_FF_FF);
        };

        self::assertSame(
            [true, true, true, true, false],
            [
                $white(
                    x: 2,
                    y: 2,
                ),
                $white(
                    x: $w - 3,
                    y: 2,
                ),
                $white(
                    x: 2,
                    y: $h - 3,
                ),
                $white(
                    x: $w - 3,
                    y: $h - 3,
                ),
                $white(
                    x: (int) ($w / 2),
                    y: (int) ($h / 2),
                ),
            ],
        );
    }
}
