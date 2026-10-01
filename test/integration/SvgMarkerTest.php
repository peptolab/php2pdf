<?php

declare(strict_types=1);

namespace PhpPdfTest\Integration;

use PhpPdf\Core\FixedClock;
use PhpPdf\Document\Dimensions;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Fit\Fit;
use PhpPdf\Geometry\Box;
use PhpPdf\Svg\SvgFile;
use PhpPdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function imagecolorat;
use function imagecreatefrompng;
use function imagesx;

/**
 * SVG markers turn with the path in every renderer (#37): a red arrowhead, ten units long at
 * stroke width 2, ends a horizontal line at x = 60 and a vertical one at y = 50.
 */
#[Group('integration')]
final class SvgMarkerTest extends TestCase
{
    private const string SVG = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" width="100" height="60" viewBox="0 0 100 60">
          <defs>
            <marker id="arrow" viewBox="0 0 10 10" refX="10" refY="5" markerWidth="5" markerHeight="5" orient="auto">
              <path d="M0 0 L10 5 L0 10 Z" fill="red"/>
            </marker>
          </defs>
          <line x1="10" y1="30" x2="60" y2="30" stroke="black" stroke-width="2" marker-end="url(#arrow)"/>
          <line x1="80" y1="10" x2="80" y2="50" stroke="black" stroke-width="2" marker-end="url(#arrow)"/>
        </svg>
        SVG;

    /** @return array<string, array{0: string}> */
    public static function rendererProvider(): array
    {
        return ['poppler' => ['poppler'], 'Ghostscript' => ['ghostscript'], 'CoreGraphics' => ['coregraphics']];
    }

    #[Test]
    #[DataProvider('rendererProvider')]
    public function arrowheadsPointAlongTheirLines(string $renderer): void
    {
        $path = PdfTools::outputDir() . '/svg-marker.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->addPage(new Dimensions(75.0, 45.0))->place(
            $doc->svg(SvgFile::fromData(self::SVG)),
            new Box(0.0, 0.0, 75.0, 45.0),
            Fit::entire(),
        );
        $doc->close();
        $png = imagecreatefrompng(match ($renderer) {
            'ghostscript'  => PdfTools::ghostscriptRender($path, dpi: 144),
            'coregraphics' => PdfTools::coreGraphicsRender($path),
            default        => PdfTools::render($path, dpi: 144),
        });
        self::assertNotFalse($png);
        $scale = imagesx($png) / 100;
        $red   = static function (array $point) use ($png, $scale): bool {
            $pixel = (int) imagecolorat($png, (int) ($point[0] * $scale), (int) ($point[1] * $scale));
            $alpha = ($pixel >> 24) & 0x7F;

            return $alpha < 64 && (($pixel >> 16) & 0xFF) > 180 && (($pixel >> 8) & 0xFF) < 100;
        };

        self::assertSame(
            ['horizontal' => [true, true, false, false], 'vertical' => [true, true, false, false]],
            [
                'horizontal' => array_map($red, [[53.0, 28.0], [56.0, 30.0], [53.0, 24.0], [64.0, 30.0]]),
                'vertical'   => array_map($red, [[78.0, 43.0], [80.0, 46.0], [74.0, 43.0], [80.0, 54.0]]),
            ],
        );
    }
}
