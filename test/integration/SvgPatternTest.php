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
use function array_map;
use function imagecolorat;
use function imagecreatefrompng;
use function imagesx;

/**
 * SVG patterns tile in every renderer (#37). Each 20-unit strip repeats a tile whose left half
 * is red and right half blue: 20 units wide in user space, a quarter of the strip by its
 * bounding box, and 20 units shifted 10 to the right by patternTransform. The SVG is placed at
 * its natural size, so poppler's Splash renderer (which rounds tile steps to whole pixels) keeps
 * its cells in place.
 */
#[Group('integration')]
final class SvgPatternTest extends TestCase
{
    private const string SVG = <<<'SVG'
        <svg xmlns="http://www.w3.org/2000/svg" width="100" height="60" viewBox="0 0 100 60">
          <defs>
            <pattern id="user" patternUnits="userSpaceOnUse" width="20" height="20">
              <rect width="10" height="20" fill="red"/><rect x="10" width="10" height="20" fill="blue"/>
            </pattern>
            <pattern id="box" width="0.25" height="1" viewBox="0 0 2 1" preserveAspectRatio="none">
              <rect width="1" height="1" fill="red"/><rect x="1" width="1" height="1" fill="blue"/>
            </pattern>
            <pattern id="moved" href="#user" patternTransform="translate(10 0)"/>
          </defs>
          <rect width="100" height="20" fill="url(#user)"/>
          <rect y="20" width="100" height="20" fill="url(#box)"/>
          <rect y="40" width="100" height="20" fill="url(#moved)"/>
        </svg>
        SVG;

    /** @return array<string, array{0: string}> */
    public static function rendererProvider(): array
    {
        return ['poppler' => ['poppler'], 'Ghostscript' => ['ghostscript'], 'CoreGraphics' => ['coregraphics']];
    }

    #[Test]
    #[DataProvider('rendererProvider')]
    public function patternsTileAcrossTheShape(string $renderer): void
    {
        $path = PdfTools::outputDir() . '/svg-pattern.pdf';
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
        $red   = static function (float $x, float $y) use ($png, $scale): bool {
            $pixel = (int) imagecolorat($png, (int) ($x * $scale), (int) ($y * $scale));
            $r     = ($pixel >> 16) & 0xFF;
            $b     = $pixel & 0xFF;

            return abs($r - $b) > 80 ? $r > $b : throw new LogicException("Neither red nor blue at {$x},{$y}");
        };
        /** @param list<float> $xs */
        $strip = static fn(float $y, array $xs): array => array_map(static fn(float $x): bool => $red($x, $y), $xs);

        self::assertSame(
            [
                'user'  => [true, false, true, false, true, false],
                'box'   => [true, false, true, false],
                'moved' => [false, true, false, true],
            ],
            [
                'user'  => $strip(10.0, [5.0, 15.0, 25.0, 35.0, 85.0, 95.0]),
                'box'   => $strip(30.0, [6.0, 19.0, 81.0, 94.0]),
                'moved' => $strip(50.0, [5.0, 15.0, 85.0, 95.0]),
            ],
        );
    }
}
