<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Canvas\TextlineOptions;
use Php2Pdf\Color\Cmyk;
use Php2Pdf\Color\Gray;
use Php2Pdf\Color\Rgb;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Fit\FitMethod;
use Php2Pdf\Fit\Position;
use Php2Pdf\Font\TextOptions;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2PdfTest\TestAsset\PdfTools;
use Php2PdfTest\TestAsset\Visual;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The v0.1 milestone: a static layout with positioned text, graphics, images and reusable
 * artwork, on a bottom-left page and a top-down page.
 */
#[Group('integration')]
final class StaticLayoutTest extends TestCase
{
    #[Test]
    public function staticLayoutMatchesBaseline(): void
    {
        $path = PdfTools::outputDir() . '/static-layout.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->info()->setTitle('Static layout');
        $sans  = $doc->fonts()->load(PdfTools::assetPath('fonts/SourceSans3-Regular.otf'));
        $bold  = $doc->fonts()->load(PdfTools::assetPath('fonts/SourceSans3-Bold.otf'));
        $photo = $doc->image(PdfTools::assetPath('images/rgb.jpg'));

        // A reusable header: band, logo mark, title set once and placed on every page.
        $header = $doc->template(Dimensions::mm(210.0, 30.0));
        $hc     = $header->canvas();
        $hc->setFillColor(Cmyk::fromPercent(100.0, 44.0, 0.0, 0.0))->fillRect(
            0.0,
            0.0,
            $header->width(),
            $header->height(),
        );
        $hc->setFillColor(Gray::white())
            ->circle(40.0, $header->height() / 2.0, 20.0)
            ->fill();
        $hc->place($photo, new Box(20.0, ($header->height() / 2.0) - 20.0, 40.0, 40.0), Fit::slice());
        $hc->setFillColor(Gray::white())->fitTextline(
            'PhpPdf',
            $bold,
            24.0,
            new Box(80.0, 0.0, 300.0, $header->height()),
            TextlineOptions::at(Position::centerLeft()),
        );
        $hc->fitTextline(
            'v0.1 static layouts',
            $sans,
            10.0,
            new Box(80.0, 0.0, $header->width() - 100.0, $header->height()),
            TextlineOptions::at(Position::centerRight()),
        );

        foreach ([false, true] as $topDown) {
            $page = $doc->addPage(PageSize::A4, $topDown);
            $page->place(
                $header,
                new Box(0.0, $topDown ? 0.0 : $page->height() - $header->height(), $page->width(), $header->height()),
                Fit::entire(),
            );

            $y = $topDown ? 120.0 : 600.0;
            $c = $page->canvas();
            $c->setFillColor(Gray::black());
            $c->fitTextline(
                $topDown ? 'Top-down page' : 'Bottom-left page',
                $bold,
                18.0,
                new Box(40.0, $y, 300.0, 24.0),
                TextlineOptions::at(Position::bottomLeft()),
            );
            $c->fitTextline(
                'Right-aligned, underlined and struck',
                $sans,
                11.0,
                new Box(300.0, $y, 255.0, 24.0),
                new TextlineOptions(Position::bottomRight(), underline: true, strikeout: true),
            );

            $y = $topDown ? 160.0 : 540.0;
            $c->setStrokeColor(Rgb::fromHex('#c2185b'))->setLineWidth(0.5)->strokeRect(40.0, $y, 200.0, 40.0);
            $c->setFillColor(Rgb::fromHex('#c2185b'))->fitTextline(
                'Shrunk to fit this narrow box',
                $sans,
                16.0,
                new Box(40.0, $y, 200.0, 40.0),
                TextlineOptions::shrinkToFit(Position::center()),
            );
            $c->setStrokeColor(Gray::black())->strokeRect(260.0, $y, 100.0, 40.0);
            $c->setFillColor(Gray::black())->fitTextline(
                'Clipped text that overflows',
                $sans,
                14.0,
                new Box(260.0, $y, 100.0, 40.0),
                new TextlineOptions(Position::centerLeft(), FitMethod::Clip),
            );
            $c->strokeRect(380.0, $y, 60.0, 40.0);
            $c->fitTextline(
                'Entire',
                $sans,
                10.0,
                new Box(380.0, $y, 60.0, 40.0),
                new TextlineOptions(Position::center(), FitMethod::Entire),
            );
            $c->strokeRect(460.0, $y, 30.0, 40.0);
            $c->fitTextline(
                'Rotated',
                $sans,
                10.0,
                new Box(460.0, $y, 30.0, 40.0),
                new TextlineOptions(Position::center(), FitMethod::Meet, 90.0),
            );

            $y = $topDown ? 240.0 : 380.0;
            $c->place($photo, new Box(40.0, $y, 150.0, 120.0), Fit::meet(Position::topLeft()));
            $c->place(
                $header,
                new Box(220.0, $y, 150.0, 120.0),
                Fit::meet(Position::center(), $topDown ? 15.0 : -15.0),
            );
            $c->drawText(
                'Tracking 50',
                $sans,
                12.0,
                400.0,
                $topDown ? $y + 20.0 : $y + 100.0,
                new TextOptions(tracking: 50.0),
            );
        }
        $doc->close();

        PdfTools::assertValid($path);
        self::assertStringContainsString('Shrunk to fit this narrow box', PdfTools::text($path, 1));
        Visual::assertMatchesBaseline($path, 'static-layout-p1', 1);
        Visual::assertMatchesBaseline($path, 'static-layout-p2', 2);
    }
}
