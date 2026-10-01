<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Canvas\BlendMode;
use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Canvas\FillRule;
use Php2Pdf\Canvas\LineCap;
use Php2Pdf\Canvas\LineJoin;
use Php2Pdf\Color\Cmyk;
use Php2Pdf\Color\GradientStop;
use Php2Pdf\Color\Gray;
use Php2Pdf\Color\LinearGradient;
use Php2Pdf\Color\RadialGradient;
use Php2Pdf\Color\Rgb;
use Php2Pdf\Color\Spot;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2PdfTest\Support\PdfTools;
use Php2PdfTest\Support\Visual;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class CanvasSheetTest extends TestCase
{
    private static function drawSheet(Canvas $c): void
    {
        // Row 1: strokes with caps, joins and dashes.
        $c->setLineWidth(12)->setStrokeColor(Gray::black());
        foreach ([LineCap::Butt, LineCap::Round, LineCap::Square] as $i => $cap) {
            $c->setLineCap($cap)->line(60 + ($i * 120), 780, 140 + ($i * 120), 780);
        }
        foreach ([LineJoin::Miter, LineJoin::Round, LineJoin::Bevel] as $i => $join) {
            $x = 60 + ($i * 120);
            $c->setLineJoin($join)
                ->moveTo($x, 700)
                ->lineTo($x + 40, 750)
                ->lineTo($x + 80, 700)
                ->stroke();
        }
        $c->setLineWidth(2)
            ->setLineCap(LineCap::Butt)
            ->setDash([6, 3])
            ->line(420, 780, 540, 780)
            ->setDash([1, 4], 0)
            ->setLineCap(LineCap::Round)
            ->setLineWidth(4)
            ->line(420, 740, 540, 740)
            ->setDash([]);

        // Row 2: shapes with device colours.
        $c->setFillColor(Rgb::fromHex('#0088b8'))->fillRect(60, 580, 100, 80);
        $c->setFillColor(Cmyk::fromPercent(0, 80, 90, 0))
            ->roundedRect(180, 580, 100, 80, 16)
            ->fill();
        $c->setFillColor(new Gray(0.3))
            ->setStrokeColor(Rgb::fromHex('#c2185b'))
            ->setLineWidth(3)
            ->circle(350, 620, 40)
            ->fillAndStroke();
        $c->setFillColor(new Spot('PANTONE 300 C', new Cmyk(1.0, 0.44, 0.0, 0.0)))
            ->ellipse(470, 620, 60, 35)
            ->fill();

        // Row 3: even-odd fill, clipping and arcs.
        $c->setFillColor(Rgb::fromHex('#333333'))
            ->rect(60, 440, 100, 100)
            ->rect(85, 465, 50, 50)
            ->fill(FillRule::EvenOdd);
        $c->save()
            ->circle(230, 490, 45)
            ->clip()
            ->setFillColor(Rgb::fromHex('#ffb300'))
            ->fillRect(180, 440, 100, 100)
            ->setFillColor(Rgb::fromHex('#0088b8'))
            ->fillRect(180, 440, 50, 100)
            ->restore();
        $c->setStrokeColor(Gray::black())->setLineWidth(6)->arc(350, 490, 45, 30, 300)->stroke();
        $c->setFillColor(Rgb::fromHex('#2e7d32'))->moveTo(470, 490)->arc(470, 490, 45, 0, 120)->closePath()->fill();

        // Row 4: gradients and transparency.
        $c->setFillGradient(new LinearGradient(60, 0, 160, 0, [
            new GradientStop(0.0, Rgb::fromHex('#ff5722')),
            new GradientStop(0.5, Rgb::fromHex('#ffeb3b')),
            new GradientStop(1.0, Rgb::fromHex('#4caf50')),
        ]))->fillRect(60, 300, 100, 100);
        $c->save()
            ->roundedRect(180, 300, 100, 100, 20)
            ->clip()
            ->paintGradient(RadialGradient::circle(230, 350, 60, [
                new GradientStop(0.0, Gray::white()),
                new GradientStop(1.0, new Gray(0.1)),
            ]))
            ->restore();
        $c->save()
            ->setFillColor(Rgb::fromHex('#0088b8'))
            ->fillRect(300, 300, 80, 80)
            ->setOpacity(0.5)
            ->setFillColor(Rgb::fromHex('#c2185b'))
            ->fillRect(340, 330, 80, 80)
            ->restore();
        $c->save()
            ->setFillColor(Rgb::fromHex('#ffeb3b'))
            ->fillRect(450, 300, 80, 80)
            ->setBlendMode(BlendMode::Multiply)
            ->setFillColor(Rgb::fromHex('#00bcd4'))
            ->fillRect(490, 330, 80, 80)
            ->restore();

        // Row 5: transforms.
        $c->save()
            ->translate(110, 180)
            ->rotate(30)
            ->setFillColor(Rgb::fromHex('#9c27b0'))
            ->fillRect(-40, -20, 80, 40)
            ->restore();
        $c->save()
            ->translate(230, 180)
            ->scale(1.5, 0.5)
            ->setStrokeColor(Gray::black())
            ->setLineWidth(2)
            ->circle(0, 0, 40)
            ->stroke()
            ->restore();
        $c->save()
            ->translate(350, 160)
            ->skew(20, 0)
            ->setFillColor(Rgb::fromHex('#607d8b'))
            ->fillRect(0, 0, 80, 50)
            ->restore();
    }

    private static function drawTopDown(Canvas $c): void
    {
        // On a top-down page y grows downwards: this square sits near the top-left corner.
        $c->setFillColor(Rgb::fromHex('#0088b8'))->fillRect(40, 40, 120, 80);
        // And a positive rotation turns clockwise.
        $c->save()
            ->translate(300, 120)
            ->rotate(30)
            ->setFillColor(Rgb::fromHex('#c2185b'))
            ->fillRect(0, 0, 120, 30)
            ->restore();
        $c->setStrokeColor(Gray::black())->setLineWidth(4)->line(40, 200, 550, 200);
    }

    #[Test]
    public function sheetMatchesBaseline(): void
    {
        $path = PdfTools::outputDir() . '/canvas-sheet.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));

        self::drawSheet($doc->addPage(PageSize::A4)->canvas());
        self::drawTopDown($doc->addPage(PageSize::A4, topDown: true)->canvas());
        $doc->close();

        PdfTools::assertValid($path);
        Visual::assertNotBlank($path, 1);
        Visual::assertNotBlank($path, 2);
        Visual::assertMatchesBaseline($path, 'canvas-sheet-p1', 1);
        Visual::assertMatchesBaseline($path, 'canvas-sheet-p2', 2);
    }
}
