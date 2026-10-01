<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Dimensions;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Svg\SvgFile;
use Php2PdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function imagecolorat;
use function imagecreatefrompng;
use function imagesx;

/**
 * An SVG mask lies over the element it masks (#37). macOS Preview is not checked: it applies the
 * transformation that places a form on the page twice to soft masks set inside it, so masks in
 * placed artwork land in the wrong place there (verified with forms placed at scale 2 and moved).
 */
#[Group('integration')]
final class SvgMaskTest extends TestCase
{
    private const string SVG = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" width="200" height="100" viewBox="0 0 200 100">
          <defs>
            <mask id="hole"><rect x="50" width="100" height="100" fill="white"/><circle cx="100" cy="50" r="30" fill="black"/></mask>
          </defs>
          <g transform="translate(50 0)"><rect x="50" width="100" height="100" fill="blue" mask="url(#hole)"/></g>
        </svg>
        SVG;

    /** @return array<string, array{0: string}> */
    public static function rendererProvider(): array
    {
        return ['poppler' => ['poppler'], 'Ghostscript' => ['ghostscript']];
    }

    /**
     * Blue around a hole the mask's black circle makes. The mask is in the masked element's user
     * space, inside a translated group, so the rectangle and the hole land at 100 to 200.
     */
    #[Test]
    #[DataProvider('rendererProvider')]
    public function theMaskCutsTheHoleWhereItIsDrawn(string $renderer): void
    {
        $path = PdfTools::outputDir() . '/svg-mask.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->addPage(new Dimensions(200.0, 100.0))->place(
            $doc->svg(SvgFile::fromData(self::SVG)),
            new Box(0.0, 0.0, 200.0, 100.0),
            Fit::entire(),
        );
        $doc->close();
        $png = imagecreatefrompng(match ($renderer) {
            'ghostscript' => PdfTools::ghostscriptRender($path, dpi: 72),
            default       => PdfTools::render($path, dpi: 72),
        });
        self::assertNotFalse($png);
        $scale = imagesx($png) / 200;
        $blue  = static function (int $x, int $y) use ($png, $scale): bool {
            $pixel = (int) imagecolorat($png, (int) ($x * $scale), (int) ($y * $scale));

            return ($pixel >> 24) < 64 && ($pixel & 0xFF) > 200 && (($pixel >> 16) & 0xFF) < 60;
        };

        self::assertSame(
            [false, true, false, true, true],
            [
                $blue(
                    x: 50,
                    y: 50,
                ),
                $blue(
                    x: 105,
                    y: 50,
                ),
                $blue(
                    x: 150,
                    y: 50,
                ),
                $blue(
                    x: 190,
                    y: 50,
                ),
                $blue(
                    x: 150,
                    y: 10,
                ),
            ],
        );
    }
}
