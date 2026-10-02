<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Color\Rgb;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function abs;
use function array_map;
use function imagecolorat;
use function imagecreatefrompng;
use function imagesx;
use function imagesy;
use function implode;

/** JPEG 2000 images pass through and render as their source pictures (#26). */
#[Group('integration')]
final class JpxImageTest extends TestCase
{
    /**
     * Alpha in JPEG 2000 data (SMaskInData) is checked with Ghostscript: poppler ignores it and
     * CoreGraphics blends it unreliably.
     *
     * @return array<string, array{0: string, 1: array{0: int, 1: int, 2: int}, 2: string}> colour at the image's centre
     */
    public static function imageProvider(): array
    {
        return [
            'grey JP2'              => ['gray.jp2', [128, 128, 128], 'poppler'],
            'grey codestream'       => ['gray.j2k', [128, 128, 128], 'poppler'],
            'RGB with alpha on red' => ['rgba.jp2', [227, 15, 15], 'ghostscript'],
        ];
    }

    /** @param array{0: int, 1: int, 2: int} $expected */
    #[Test]
    #[DataProvider('imageProvider')]
    public function rendersThePicture(string $file, array $expected, string $renderer): void
    {
        $path = PdfTools::outputDir() . "/jpx-{$file}.pdf";
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $page = $doc->addPage(new Dimensions(64.0, 48.0));
        $page->canvas()->setFillColor(Rgb::fromHex('#ff0000'))->fillRect(0.0, 0.0, 64.0, 48.0);
        $page->place($doc->image(PdfTools::assetPath("images/{$file}")), new Box(0.0, 0.0, 64.0, 48.0), Fit::entire());
        $doc->close();

        PdfTools::assertValid($path);
        $png = imagecreatefrompng(
            'poppler' === $renderer ? PdfTools::render($path, dpi: 144) : PdfTools::ghostscriptRender($path, dpi: 144),
        );
        self::assertNotFalse($png);
        $pixel       = (int) imagecolorat($png, (int) (imagesx($png) / 2), (int) (imagesy($png) / 2));
        $actual      = [($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF];
        $differences = array_map(static fn(int $got, int $want): int => abs($got - $want), $actual, $expected);
        foreach ($differences as $i => $difference) {
            self::assertLessThanOrEqual(
                24,
                $difference,
                "Channel {$i} of {$file}: " . implode(',', $actual),
            );
        }
    }
}
