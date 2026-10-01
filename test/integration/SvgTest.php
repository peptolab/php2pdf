<?php

declare(strict_types=1);

namespace PhpPdfTest\Integration;

use PhpPdf\Core\FixedClock;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\PageSize;
use PhpPdf\Fit\Fit;
use PhpPdf\Geometry\Box;
use PhpPdf\Svg\NativeConverter;
use PhpPdf\Svg\RsvgConverter;
use PhpPdf\Svg\SvgFile;
use PhpPdfTest\Support\PdfTools;
use PhpPdfTest\Support\Visual;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function abs;
use function imagecolorat;
use function imagecreatefrompng;
use function imagesx;
use function imagesy;
use function max;
use function sprintf;

/**
 * Phase 8: SVG through the native converter (baseline render) and, when rsvg-convert is
 * installed, through the external converter, with the two renders compared to each other.
 */
final class SvgTest extends TestCase
{
    private static function differingShare(string $a, string $b): float
    {
        $ia = imagecreatefrompng($a);
        $ib = imagecreatefrompng($b);
        self::assertNotFalse($ia);
        self::assertNotFalse($ib);
        $w = imagesx($ia);
        $h = imagesy($ia);
        self::assertSame([$w, $h], [imagesx($ib), imagesy($ib)]);
        $differing = 0;
        for ($y = 0; $y < $h; ++$y) {
            for ($x = 0; $x < $w; ++$x) {
                $pa    = imagecolorat($ia, $x, $y);
                $pb    = imagecolorat($ib, $x, $y);
                $delta = abs((($pa >> 16) & 0xFF) - (($pb >> 16) & 0xFF))
                + abs((($pa >> 8) & 0xFF) - (($pb >> 8) & 0xFF))
                + abs(($pa & 0xFF) - ($pb & 0xFF));
                if ($delta > 96) {
                    ++$differing;
                }
            }
        }

        return $differing / max(1, $w * $h);
    }

    #[Test]
    public function nativeConverterRendersTheSheet(): void
    {
        $path = PdfTools::outputDir() . '/svg-native.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->fonts()->addSearchPath(PdfTools::assetPath('fonts'));
        $fallback  = $doc->fonts()->load(PdfTools::assetPath('fonts/SourceSans3-Regular.otf'));
        $converter = new NativeConverter($fallback);

        $shapes = $doc->svg(PdfTools::assetPath('svg/shapes.svg'), $converter);
        $sheet  = $doc->svg(SvgFile::open(PdfTools::assetPath('svg/sheet.svg')), $converter);
        $page   = $doc->addPage(PageSize::A4);
        $page->place($shapes, Box::fromMm(10.0, 150.0, 190.0, 133.0), Fit::meet());
        $page->place($sheet, Box::fromMm(10.0, 20.0, 190.0, 120.0), Fit::meet());
        $doc->close();

        PdfTools::assertValid($path);
        self::assertSame(['filter'], $converter->warnings());
        self::assertStringContainsString('PhpPdf SVG', PdfTools::text($path));
        Visual::assertMatchesBaseline($path, 'svg-native');
    }

    #[Test]
    public function rsvgConverterAgreesWithTheNativeConverter(): void
    {
        $rsvg = new RsvgConverter();
        if (! $rsvg->isAvailable()) {
            self::markTestSkipped('rsvg-convert is not installed.');
        }
        $renders = [];
        foreach (['native' => new NativeConverter(), 'rsvg' => $rsvg] as $name => $converter) {
            $path = PdfTools::outputDir() . "/svg-compare-{$name}.pdf";
            $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
            $art  = $doc->svg(PdfTools::assetPath('svg/shapes.svg'), $converter);
            $doc->addPage(PageSize::A4)->place($art, Box::fromMm(5.0, 5.0, 200.0, 140.0), Fit::entire());
            $doc->close();
            PdfTools::assertValid($path);
            $renders[$name] = PdfTools::render($path, 1, 72);
        }
        $share = self::differingShare($renders['native'] ?? '', $renders['rsvg'] ?? '');
        self::assertLessThan(
            0.02,
            $share,
            sprintf('Native and rsvg renders differ in %.2f%% of pixels.', $share * 100),
        );
    }
}
