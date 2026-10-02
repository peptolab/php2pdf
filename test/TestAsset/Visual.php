<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset;

use GdImage;
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
use function min;
use function sprintf;

/**
 * Pixel comparison of rendered pages against baseline PNGs in test/asset/baseline, rendered at
 * 150 dpi by poppler.
 *
 * The page is compared tile by tile, so a change confined to one area (a missing line, a moved
 * word) fails even when it is a tiny share of the whole page. Within a tile, a small share of
 * differing pixels is allowed so anti-aliasing differences between poppler versions pass.
 *
 * Run with PHP2PDF_UPDATE_BASELINES=1 to write every baseline the run reaches; a missing baseline
 * fails otherwise.
 */
final class Visual
{
    private const int DPI = 150;

    /** Tile edge in pixels. */
    private const int TILE = 32;

    /** Colour distance (summed over channels) below which pixels count as the same. */
    private const int THRESHOLD = 48;

    public static function assertMatchesBaseline(
        string $pdfPath,
        string $name,
        int $page = 1,
        float $tolerance = 0.02,
    ): void {
        if (! extension_loaded('gd')) {
            Assert::markTestSkipped('ext-gd is needed for visual comparison.');
        }
        $png      = PdfTools::render($pdfPath, $page, self::DPI);
        $baseline = PdfTools::assetPath("baseline/{$name}.png");

        if ('1' === getenv('PHP2PDF_UPDATE_BASELINES')) {
            copy($png, $baseline);
            Assert::assertFileExists($baseline);

            return;
        }
        if (! is_file($baseline)) {
            Assert::fail("Baseline {$name}.png is missing; run with PHP2PDF_UPDATE_BASELINES=1 to write it.");
        }

        $actual   = imagecreatefrompng($png);
        $expected = imagecreatefrompng($baseline);
        Assert::assertNotFalse($actual);
        Assert::assertNotFalse($expected);

        $width  = imagesx($expected);
        $height = imagesy($expected);
        Assert::assertSame([$width, $height], [imagesx($actual), imagesy($actual)], "Render size differs for {$name}.");

        $worst = [0.0, 0, 0];
        for ($top = 0; $top < $height; $top += self::TILE) {
            for ($left = 0; $left < $width; $left += self::TILE) {
                $share = self::tileDifference($actual, $expected, $left, $top, $width, $height);
                if ($share > $worst[0]) {
                    $worst = [$share, $left, $top];
                }
            }
        }
        Assert::assertLessThanOrEqual(
            $tolerance,
            $worst[0],
            sprintf(
                '%s differs from baseline in %.1f%% of the %dpx tile at (%d, %d) (see %s).',
                $name,
                $worst[0] * 100,
                self::TILE,
                $worst[1],
                $worst[2],
                $png,
            ),
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

    /** The share of a tile's pixels whose colours differ by more than the threshold. */
    private static function tileDifference(
        GdImage $actual,
        GdImage $expected,
        int $left,
        int $top,
        int $width,
        int $height,
    ): float {
        $right     = min($width, $left + self::TILE);
        $bottom    = min($height, $top + self::TILE);
        $differing = 0;
        for ($y = $top; $y < $bottom; ++$y) {
            for ($x = $left; $x < $right; ++$x) {
                $a = imagecolorat($actual, $x, $y);
                $e = imagecolorat($expected, $x, $y);
                if ($a === $e) {
                    continue;
                }
                $delta =
                    abs((($a >> 16) & 0xFF) - (($e >> 16) & 0xFF))
                    + abs((($a >> 8) & 0xFF) - (($e >> 8) & 0xFF))
                    + abs(($a & 0xFF) - ($e & 0xFF));
                if ($delta > self::THRESHOLD) {
                    ++$differing;
                }
            }
        }

        return $differing / max(1, ($right - $left) * ($bottom - $top));
    }
}
