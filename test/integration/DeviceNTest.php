<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Color\Cmyk;
use Php2Pdf\Color\ColorInterface;
use Php2Pdf\Color\DeviceN;
use Php2Pdf\Color\Rgb;
use Php2Pdf\Color\Spot;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Dimensions;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2PdfTest\Support\PdfTools;
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
 * DeviceN colours mix their inks in the alternate space as overprinted inks do (#37): each
 * DeviceN colour renders like the device colour the mix works out to, in every renderer.
 */
#[Group('integration')]
final class DeviceNTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function caseProvider(): array
    {
        $renderers = ['poppler', 'ghostscript', 'coregraphics'];
        $cases     = ['process', 'spot over black', 'additive'];
        $out       = [];
        foreach ($renderers as $renderer) {
            foreach ($cases as $case) {
                $out["{$case} in {$renderer}"] = [$case, $renderer];
            }
        }

        return $out;
    }

    #[Test]
    #[DataProvider('caseProvider')]
    public function inksMixLikeTheirDeviceColour(string $case, string $renderer): void
    {
        $orange = new Spot('Fictional Orange', new Cmyk(0.0, 0.5, 1.0, 0.0));
        [$mix, $expected] = match ($case) {
            'process'         => [new DeviceN([DeviceN::cyan(), DeviceN::magenta(0.6)]), new Cmyk(1.0, 0.6, 0.0, 0.0)],
            'spot over black' => [
                new DeviceN([$orange->withTint(0.5), DeviceN::black(0.3)]),
                new Cmyk(0.0, 0.25, 0.5, 0.3),
            ],
            default           => [
                new DeviceN([
                    new Spot('Red Ink', new Rgb(1.0, 0.0, 0.0), 0.5),
                    new Spot('Blue Ink', new Rgb(0.0, 0.0, 1.0)),
                ]),
                new Rgb(0.0, 0.0, 0.5),
            ],
        };
        $png = imagecreatefrompng($this->render($mix, $expected, $renderer));
        self::assertNotFalse($png);
        $scale = imagesx($png) / 100;
        $a     = (int) imagecolorat($png, (int) (25 * $scale), (int) (20 * $scale));
        $b     = (int) imagecolorat($png, (int) (75 * $scale), (int) (20 * $scale));
        $delta = 0;
        foreach ([16, 8, 0] as $shift) {
            $delta = max($delta, abs((($a >> $shift) & 0xFF) - (($b >> $shift) & 0xFF)));
        }

        self::assertLessThanOrEqual(
            2,
            $delta,
            sprintf('Mix #%06X, device colour #%06X', $a & 0xFF_FF_FF, $b & 0xFF_FF_FF),
        );
    }

    private function render(DeviceN $mix, ColorInterface $expected, string $renderer): string
    {
        $path = PdfTools::outputDir() . '/devicen.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->addPage(new Dimensions(100.0, 40.0))
            ->canvas()
            ->setFillColor($mix)
            ->rect(0.0, 0.0, 50.0, 40.0)
            ->fill()
            ->setFillColor($expected)
            ->rect(50.0, 0.0, 50.0, 40.0)
            ->fill();
        $doc->close();
        PdfTools::assertValid($path);

        return match ($renderer) {
            'ghostscript'  => PdfTools::ghostscriptRender($path, dpi: 72),
            'coregraphics' => PdfTools::coreGraphicsRender($path),
            default        => PdfTools::render($path, dpi: 72),
        };
    }
}
