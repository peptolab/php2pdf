<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Template;

use Php2Pdf\Color\Rgb;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Dimensions;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Fit\Position;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Origin;
use Php2PdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function substr_count;

final class TemplateTest extends TestCase
{
    #[Test]
    public function topDownTemplatesFlipTheirContent(): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(
            clock: new FixedClock('2026-01-01'),
            compress: false,
            xmp: false,
        ));
        $tpl = $doc->template(Dimensions::pt(100.0, 40.0), Origin::TopLeft);
        self::assertTrue($tpl->origin()->isTopDown());
        $tpl->canvas()->fillRect(0.0, 0.0, 10.0, 10.0);
        $doc->addPage()->place($tpl, new Box(0.0, 0.0, 100.0, 40.0));
        $doc->close();

        self::assertStringContainsString("1 0 0 -1 0 40 cm\n0 0 10 10 re\nf\n", $out->contents());
    }

    #[Test]
    public function writesAFormXObjectOnceAndPlacesItThroughTheFitEngine(): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(
            clock: new FixedClock('2026-01-01'),
            compress: false,
            xmp: false,
        ));
        $font = $doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));

        $logo = $doc->template(new Dimensions(100.0, 50.0));
        $logo->canvas()
            ->setFillColor(Rgb::fromHex('#0088b8'))
            ->fillRect(0.0, 0.0, 100.0, 50.0)
            ->drawText('Logo', $font, 20.0, 10.0, 15.0);
        $logo->canvas()->place($doc->image(PdfTools::assetPath('images/rgb.jpg')), new Box(60.0, 5.0, 35.0, 40.0));

        $page = $doc->addPage();
        $a    = $page->place($logo, new Box(0.0, 0.0, 200.0, 200.0), Fit::meet(Position::bottomLeft()));
        $b    = $page->place($logo, new Box(300.0, 0.0, 50.0, 50.0), Fit::meet(Position::topRight(), 90.0));
        $doc->close();
        $pdf = $out->contents();

        self::assertEqualsWithDelta([0.0, 0.0, 200.0, 100.0], $a->bounds->toRect(), 1e-9);
        self::assertEqualsWithDelta([325.0, 0.0, 350.0, 50.0], $b->bounds->toRect(), 1e-9);
        self::assertSame(1, substr_count($pdf, needle: '/Subtype/Form'));
        self::assertStringContainsString('/Type/XObject/Subtype/Form/FormType 1/BBox[0 0 100 50]/Resources<<', $pdf);
        self::assertStringContainsString("q 2 0 0 2 0 0 cm /X1 Do Q\n", $pdf);
        self::assertStringContainsString("q 0 0.5 -0.5 0 350 0 cm /X1 Do Q\n", $pdf);
        // The template's own resources carry the font and the image it used.
        self::assertMatchesRegularExpression(
            '/BBox\[0 0 100 50\]\/Resources<<[^>]*\/Font<<\/F1 \d+ 0 R>>[^>]*\/XObject<<\/X1 \d+ 0 R>>/',
            $pdf,
        );
    }
}
