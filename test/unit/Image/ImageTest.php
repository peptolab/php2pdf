<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Image;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Fit\Position;
use Php2Pdf\Geometry\Box;
use Php2PdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function substr_count;

final class ImageTest extends TestCase
{
    #[Test]
    public function flipsImagesOnTopDownPages(): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(
            clock: new FixedClock('2026-01-01'),
            compress: false,
            xmp: false,
        ));
        $jpg = $doc->image(PdfTools::assetPath('images/rgb.jpg'));
        $doc->addPage(topDown: true)->place($jpg, new Box(10.0, 20.0, 64.0, 48.0), Fit::noFit(Position::topLeft()));
        $doc->close();

        // Negative d keeps the image upright in the flipped space; it spans y 20..68 downwards.
        self::assertStringContainsString("q 64 0 0 -48 10 68 cm /X1 Do Q\n", $out->contents());
    }

    #[Test]
    public function writesImagesOnceAndPlacesThemThroughTheFitEngine(): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(
            clock: new FixedClock('2026-01-01'),
            compress: false,
            xmp: false,
        ));
        $jpg = $doc->image(PdfTools::assetPath('images/rgb.jpg'));
        $png = $doc->image(PdfTools::assetPath('images/rgba8.png'));

        self::assertSame($jpg, $doc->image(PdfTools::assetPath('images/rgb.jpg')), 'Same bytes embed once.');
        self::assertSame([64, 48, 72.0], [$jpg->widthPx(), $jpg->heightPx(), $jpg->dpiX()]);
        self::assertEqualsWithDelta(64.0, $jpg->naturalSize()->width, 1e-9);
        self::assertEqualsWithDelta(32.0, $jpg->naturalSize(144.0)->width, 1e-9);
        self::assertTrue($png->hasTransparency());

        $page      = $doc->addPage();
        $placement = $page->place($jpg, new Box(10.0, 10.0, 128.0, 128.0), Fit::meet(Position::bottomLeft()));
        self::assertEqualsWithDelta([10.0, 10.0, 138.0, 106.0], $placement->bounds->toRect(), 1e-9);
        $page->place($png, new Box(200.0, 10.0, 50.0, 50.0), Fit::slice());
        $page->place($jpg, new Box(300.0, 10.0, 50.0, 50.0), Fit::noFit()->withDpi(300.0));
        $doc->close();

        $pdf = $out->contents();
        self::assertSame(1, substr_count($pdf, needle: '/Filter/DCTDecode'));
        self::assertStringContainsString(
            '/Type/XObject/Subtype/Image/Width 64/Height 48/ColorSpace/DeviceRGB/BitsPerComponent 8/Filter/DCTDecode',
            $pdf,
        );
        self::assertStringContainsString('/SMask ', $pdf);
        self::assertStringContainsString("q 128 0 0 96 10 10 cm /X1 Do Q\n", $pdf);
        self::assertStringContainsString(
            "q\n200 10 50 50 re\nW n\nq 66.666667 0 0 50 191.666667 10 cm /X2 Do Q\nQ\n",
            $pdf,
        );
        self::assertStringContainsString("q 15.36 0 0 11.52 317.32 29.24 cm /X1 Do Q\n", $pdf);
        self::assertStringContainsString('/XObject<</X1 ', $pdf);
    }
}
