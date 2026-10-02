<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Dimensions;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function imagecolorat;
use function imagecreatefrompng;
use function imagesx;

/** TIFF images read natively render as their source pictures (#26). */
#[Group('integration')]
final class TiffImageTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function faxProvider(): array
    {
        return [
            'Group 4, poppler'      => ['fax-g4.tif', 'poppler'],
            'Group 4, Ghostscript'  => ['fax-g4.tif', 'ghostscript'],
            'Group 4, CoreGraphics' => ['fax-g4.tif', 'coregraphics'],
            'Group 3, poppler'      => ['fax-g3.tif', 'poppler'],
            'Group 3, Ghostscript'  => ['fax-g3.tif', 'ghostscript'],
        ];
    }

    /** The fax is a checkerboard of 8-pixel squares, white at the top left. */
    #[Test]
    #[DataProvider('faxProvider')]
    public function faxImagesPassThroughTheRightWayRound(string $file, string $renderer): void
    {
        $path = $this->draw($file);
        $png  = imagecreatefrompng($this->render($path, $renderer));
        self::assertNotFalse($png);
        $square = imagesx($png) / 8;
        $grey   = static fn(int $x, int $y): int => (
            (int) imagecolorat($png, (int) (($x + 0.5) * $square), (int) (($y + 0.5) * $square)) & 0xFF
        );

        self::assertSame([255, 0, 0, 255], [
            $grey(
                x: 0,
                y: 0,
            ),
            $grey(
                x: 1,
                y: 0,
            ),
            $grey(
                x: 0,
                y: 1,
            ),
            $grey(
                x: 1,
                y: 1,
            ),
        ]);
    }

    #[Test]
    public function lzwWithAPredictorDecodes(): void
    {
        $png = imagecreatefrompng(PdfTools::render($this->draw('rgb-lzw.tif'), dpi: 72));
        self::assertNotFalse($png);
        $pixel = (int) imagecolorat($png, x: 4, y: 4);

        self::assertSame([200, 30, 30], [($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF]);
    }

    private function draw(string $file): string
    {
        $path = PdfTools::outputDir() . "/tiff-{$file}.pdf";
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->addPage(new Dimensions(64.0, 64.0))->place(
            $doc->image(PdfTools::assetPath("images/{$file}")),
            new Box(0.0, 0.0, 64.0, 64.0),
            Fit::entire(),
        );
        $doc->close();
        PdfTools::assertValid($path);

        return $path;
    }

    private function render(string $path, string $renderer): string
    {
        return match ($renderer) {
            'ghostscript'  => PdfTools::ghostscriptRender($path, dpi: 72),
            'coregraphics' => PdfTools::coreGraphicsRender($path),
            default        => PdfTools::render($path, dpi: 72),
        };
    }
}
