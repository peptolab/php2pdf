<?php

declare(strict_types=1);

namespace PhpPdfTest\Integration;

use PhpPdf\Core\FixedClock;
use PhpPdf\Document\Dimensions;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function imagecolorat;
use function imagecreatefrompng;
use function imagesx;
use function imagesy;
use function trim;

/** A COLR colour glyph is drawn in its layers' colours by every renderer, and copies as text (#39). */
#[Group('integration')]
final class ColourFontTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function rendererProvider(): array
    {
        return ['poppler' => ['poppler'], 'Ghostscript' => ['ghostscript'], 'CoreGraphics' => ['coregraphics']];
    }

    #[Test]
    #[DataProvider('rendererProvider')]
    public function theLayersAreDrawnInTheirColours(string $renderer): void
    {
        $path = $this->draw();
        $png  = imagecreatefrompng(match ($renderer) {
            'ghostscript'  => PdfTools::ghostscriptRender($path, dpi: 144),
            'coregraphics' => PdfTools::coreGraphicsRender($path),
            default        => PdfTools::render($path, dpi: 144),
        });
        self::assertNotFalse($png);
        $red  = 0;
        $blue = 0;
        for ($y = 0, $h = imagesy($png); $y < $h; ++$y) {
            for ($x = 0, $w = imagesx($png); $x < $w; ++$x) {
                $pixel = imagecolorat($png, $x, $y);
                [$r, $g, $b] = [($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF];
                if ($r > 200 && $g < 60 && $b < 60) {
                    ++$red;
                }
                if ($b > 200 && $r < 160 && $g < 160) {
                    ++$blue;
                }
            }
        }

        self::assertSame([true, true], [$red > 200, $blue > 20], "{$red} red and {$blue} blue pixels");
    }

    #[Test]
    public function theTextCopiesWithoutTheLayers(): void
    {
        self::assertSame('AB A', trim(PdfTools::text($this->draw()), characters: " \n\f"));
    }

    private function draw(): string
    {
        $path = PdfTools::outputDir() . '/colour-font.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc
            ->addPage(new Dimensions(200.0, 60.0))
            ->canvas()
            ->drawText(
                'AB A',
                $doc->fonts()->load(PdfTools::assetPath('fonts/PhpPdfColourTest.ttf')),
                36.0,
                10.0,
                15.0,
            );
        $doc->close();
        PdfTools::assertValid($path);

        return $path;
    }
}
