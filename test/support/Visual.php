<?php

declare(strict_types=1);

namespace PhpPdfTest\Support;

use PHPUnit\Framework\Assert;

use function abs;
use function copy;
use function extension_loaded;
use function getenv;
use function imagecolorat;
use function imagecreatefrompng;
use function imagesx;
use function imagesy;
use function is_file;
use function max;
use function sprintf;

/**
 * Pixel comparison of rendered pages against baseline PNGs in test/asset/baseline.
 *
 * Run with PHPPDF_UPDATE_BASELINES=1 to (re)create baselines. Comparison allows a small share of
 * differing pixels so anti-aliasing differences between poppler versions do not fail the build.
 */
final class Visual
{
    public static function assertMatchesBaseline(
        string $pdfPath,
        string $name,
        int $page = 1,
        float $tolerance = 0.005,
    ): void {
        if (! extension_loaded('gd')) {
            Assert::markTestSkipped('ext-gd is needed for visual comparison.');
        }
        $png      = PdfTools::render($pdfPath, $page, 72);
        $baseline = PdfTools::assetPath("baseline/{$name}.png");

        if ('1' === getenv('PHPPDF_UPDATE_BASELINES') || ! is_file($baseline)) {
            copy($png, $baseline);
            Assert::markTestIncomplete("Baseline {$name}.png was written; re-run to compare.");
        }

        $actual   = imagecreatefrompng($png);
        $expected = imagecreatefrompng($baseline);
        Assert::assertNotFalse($actual);
        Assert::assertNotFalse($expected);

        $width  = imagesx($expected);
        $height = imagesy($expected);
        Assert::assertSame([$width, $height], [imagesx($actual), imagesy($actual)], "Render size differs for {$name}.");

        $differing = 0;
        for ($y = 0; $y < $height; ++$y) {
            for ($x = 0; $x < $width; ++$x) {
                $a = imagecolorat($actual, $x, $y);
                $e = imagecolorat($expected, $x, $y);
                if ($a === $e) {
                    continue;
                }
                $delta =
                    abs((($a >> 16) & 0xFF) - (($e >> 16) & 0xFF))
                    + abs((($a >> 8) & 0xFF) - (($e >> 8) & 0xFF))
                    + abs(($a & 0xFF) - ($e & 0xFF));
                if ($delta > 48) {
                    ++$differing;
                }
            }
        }
        $share = $differing / max(1, $width * $height);
        Assert::assertLessThanOrEqual(
            $tolerance,
            $share,
            sprintf('%s differs from baseline in %.2f%% of pixels (see %s).', $name, $share * 100, $png),
        );
    }

    /** Fails if the rendered page is entirely one colour. */
    public static function assertNotBlank(string $pdfPath, int $page = 1): void
    {
        if (! extension_loaded('gd')) {
            Assert::markTestSkipped('ext-gd is needed for visual checks.');
        }
        $image = imagecreatefrompng(PdfTools::render($pdfPath, $page, 36));
        Assert::assertNotFalse($image);
        $first = imagecolorat($image, x: 0, y: 0);
        for ($y = 0, $h = imagesy($image); $y < $h; ++$y) {
            for ($x = 0, $w = imagesx($image); $x < $w; ++$x) {
                if (imagecolorat($image, $x, $y) === $first) {
                    continue;
                }

                Assert::assertTrue(true);

                return;
            }
        }
        Assert::fail("Page {$page} of {$pdfPath} rendered blank.");
    }
}
