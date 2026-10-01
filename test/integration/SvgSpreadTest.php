<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use LogicException;
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

use function abs;
use function imagecolorat;
use function imagecreatefrompng;
use function imagesx;

/**
 * SVG gradients repeat and reflect beyond their vector in every renderer (#37). The gradient
 * runs red to blue over the first quarter of each 100-unit strip.
 */
#[Group('integration')]
final class SvgSpreadTest extends TestCase
{
    private const string SVG = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" width="100" height="60" viewBox="0 0 100 60">
          <defs>
            <linearGradient id="rep" x1="0" x2="0.25" spreadMethod="repeat"><stop offset="0" stop-color="red"/><stop offset="1" stop-color="blue"/></linearGradient>
            <linearGradient id="ref" x1="0" x2="0.25" spreadMethod="reflect"><stop offset="0" stop-color="red"/><stop offset="1" stop-color="blue"/></linearGradient>
            <radialGradient id="rad" cx="0" cy="0.5" r="0.25" spreadMethod="repeat" gradientUnits="objectBoundingBox"><stop offset="0" stop-color="red"/><stop offset="1" stop-color="blue"/></radialGradient>
          </defs>
          <rect width="100" height="20" fill="url(#rep)"/>
          <rect y="20" width="100" height="20" fill="url(#ref)"/>
          <rect y="40" width="100" height="20" fill="url(#rad)"/>
        </svg>
        SVG;

    /** @return array<string, array{0: string}> */
    public static function rendererProvider(): array
    {
        return ['poppler' => ['poppler'], 'Ghostscript' => ['ghostscript'], 'CoreGraphics' => ['coregraphics']];
    }

    /** At x = 30 (t = 1.2) repeat is near red and reflect near blue; at x = 45 (t = 1.8) the reverse. */
    #[Test]
    #[DataProvider('rendererProvider')]
    public function gradientsRepeatAndReflect(string $renderer): void
    {
        $path = PdfTools::outputDir() . '/svg-spread.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->addPage(new Dimensions(100.0, 60.0))->place(
            $doc->svg(SvgFile::fromData(self::SVG)),
            new Box(0.0, 0.0, 100.0, 60.0),
            Fit::entire(),
        );
        $doc->close();
        $png = imagecreatefrompng(match ($renderer) {
            'ghostscript'  => PdfTools::ghostscriptRender($path, dpi: 72),
            'coregraphics' => PdfTools::coreGraphicsRender($path),
            default        => PdfTools::render($path, dpi: 72),
        });
        self::assertNotFalse($png);
        $scale     = imagesx($png) / 100;
        $mostlyRed = static function (int $x, int $y) use ($png, $scale): bool {
            $pixel = (int) imagecolorat($png, (int) ($x * $scale), (int) ($y * $scale));
            $red   = ($pixel >> 16) & 0xFF;
            $blue  = $pixel & 0xFF;

            return abs($red - $blue) > 80
                ? $red > $blue
                : throw new LogicException("Neither red nor blue at {$x},{$y}");
        };

        self::assertSame(
            ['repeat' => [true, false], 'reflect' => [false, true], 'radial' => [true, false]],
            [
                'repeat'  => [
                    $mostlyRed(
                        x: 30,
                        y: 10,
                    ),
                    $mostlyRed(
                        x: 45,
                        y: 10,
                    ),
                ],
                'reflect' => [
                    $mostlyRed(
                        x: 30,
                        y: 30,
                    ),
                    $mostlyRed(
                        x: 45,
                        y: 30,
                    ),
                ],
                'radial'  => [
                    $mostlyRed(
                        x: 28,
                        y: 50,
                    ),
                    $mostlyRed(
                        x: 45,
                        y: 50,
                    ),
                ],
            ],
        );
    }
}
