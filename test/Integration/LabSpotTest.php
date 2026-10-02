<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Color\Lab;
use Php2Pdf\Color\Spot;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Dimensions;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function abs;
use function imagecolorat;
use function imagecreatefrompng;
use function imagesx;
use function max;
use function sprintf;

/**
 * A spot colour defined in Lab renders as its Lab value, and its tints as Lab mixed with white,
 * in every renderer (#37).
 */
#[Group('integration')]
final class LabSpotTest extends TestCase
{
    /** @return array<string, array{0: string, 1: float}> */
    public static function caseProvider(): array
    {
        $out = [];
        foreach (['poppler', 'ghostscript', 'coregraphics'] as $renderer) {
            $out["full tint in {$renderer}"] = [$renderer, 1.0];
            $out["half tint in {$renderer}"] = [$renderer, 0.5];
        }

        return $out;
    }

    #[Test]
    #[DataProvider('caseProvider')]
    public function aLabSpotRendersAsItsLabValue(string $renderer, float $tint): void
    {
        $ink  = new Lab(60.0, -35.0, -5.0);
        $mix  = new Lab(100.0 - ($tint * 40.0), $tint * -35.0, $tint * -5.0);
        $path = PdfTools::outputDir() . '/lab-spot.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->addPage(new Dimensions(100.0, 40.0))
            ->canvas()
            ->setFillColor(new Spot('Harbour Blue', $ink, $tint))
            ->rect(0.0, 0.0, 50.0, 40.0)
            ->fill()
            ->setFillColor($mix)
            ->rect(50.0, 0.0, 50.0, 40.0)
            ->fill();
        $doc->close();
        $png = imagecreatefrompng(match ($renderer) {
            'ghostscript'  => PdfTools::ghostscriptRender($path, dpi: 72),
            'coregraphics' => PdfTools::coreGraphicsRender($path),
            default        => PdfTools::render($path, dpi: 72),
        });
        self::assertNotFalse($png);
        $scale = imagesx($png) / 100;
        $a     = (int) imagecolorat($png, (int) (25 * $scale), (int) (20 * $scale));
        $b     = (int) imagecolorat($png, (int) (75 * $scale), (int) (20 * $scale));
        $delta = 0;
        foreach ([16, 8, 0] as $shift) {
            $delta = max($delta, abs((($a >> $shift) & 0xFF) - (($b >> $shift) & 0xFF)));
        }

        self::assertSame(
            [true, true],
            [($b & 0xFF_FF_FF) !== 0xFF_FF_FF, $delta <= 2],
            sprintf('Spot #%06X, Lab #%06X', $a & 0xFF_FF_FF, $b & 0xFF_FF_FF),
        );
    }
}
