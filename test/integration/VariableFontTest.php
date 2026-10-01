<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use GdImage;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Dimensions;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2PdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function imagecolorat;
use function imagecreatefrompng;
use function imagesx;
use function imagesy;

/**
 * A variable font with CFF2 outlines draws its default instance (#39): the same text in it and
 * in the static font of its default master renders to the same pixels.
 */
#[Group('integration')]
final class VariableFontTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function rendererProvider(): array
    {
        return ['poppler' => ['poppler'], 'Ghostscript' => ['ghostscript'], 'CoreGraphics' => ['coregraphics']];
    }

    #[Test]
    #[DataProvider('rendererProvider')]
    public function theDefaultInstanceRendersLikeItsMaster(string $renderer): void
    {
        $variable = $this->render($this->draw('PhpPdfVariable.otf'), $renderer);
        $static   = $this->render($this->draw('PhpPdfVariable-Default.otf'), $renderer);
        [$w, $h] = [imagesx($static), imagesy($static)];
        $ink    = 0;
        $differ = 0;
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                $pixel = imagecolorat($static, $x, $y);
                if (0xFF_FF_FF !== ($pixel & 0xFF_FF_FF)) {
                    ++$ink;
                }
                if (imagecolorat($variable, $x, $y) !== $pixel) {
                    ++$differ;
                }
            }
        }

        self::assertSame([true, 0], [$ink > 1000, $differ]);
    }

    private function draw(string $font): string
    {
        $path = PdfTools::outputDir() . "/variable-{$font}.pdf";
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->addPage(new Dimensions(400.0, 60.0))
            ->canvas()
            ->drawText(
                'Variable Heading 2026',
                $doc->fonts()->load(PdfTools::assetPath("fonts/{$font}")),
                30.0,
                10.0,
                20.0,
            );
        $doc->close();
        PdfTools::assertValid($path);

        return $path;
    }

    private function render(string $path, string $renderer): GdImage
    {
        $image = imagecreatefrompng(match ($renderer) {
            'ghostscript'  => PdfTools::ghostscriptRender($path, dpi: 144),
            'coregraphics' => PdfTools::coreGraphicsRender($path),
            default        => PdfTools::render($path, dpi: 144),
        });
        self::assertNotFalse($image);

        return $image;
    }
}
