<?php

declare(strict_types=1);

namespace PhpPdfTest\Integration;

use PhpPdf\Color\Gray;
use PhpPdf\Color\Rgb;
use PhpPdf\Core\FixedClock;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\PageSize;
use PhpPdf\Fit\Fit;
use PhpPdf\Fit\FitMethod;
use PhpPdf\Fit\Position;
use PhpPdf\Geometry\Box;
use PhpPdfTest\Support\PdfTools;
use PhpPdfTest\Support\Visual;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function intdiv;

final class ImageSheetTest extends TestCase
{
    private const array FILES = [
        'rgb.jpg',
        'gray.jpg',
        'cmyk.jpg',
        'progressive.jpg',
        'rgb-150dpi.jpg',
        'rgb8.png',
        'rgb16.png',
        'gray8.png',
        'gray4.png',
        'gray1.png',
        'indexed.png',
        'indexed-trns.png',
        'rgba8.png',
        'gray-alpha.png',
        'colorkey.png',
        'interlaced-rgb.png',
        'interlaced-rgba.png',
        'interlaced-gray1.png',
        'rgb-150dpi.png',
    ];

    #[Test]
    public function everyFormatAndFitMethodMatchesBaseline(): void
    {
        $path = PdfTools::outputDir() . '/image-sheet.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $font = $doc->fonts()->load(PdfTools::assetPath('fonts/SourceSans3-Regular.otf'));

        $c = $doc->addPage(PageSize::A4)->canvas();
        $c->setFillColor(Rgb::fromHex('#ffe082'))->fillRect(0.0, 0.0, 595.0, 842.0);
        foreach (self::FILES as $i => $file) {
            $image = $doc->image(PdfTools::assetPath("images/{$file}"));
            $x     = 30.0 + (($i % 5) * 110.0);
            $y     = 760.0 - (intdiv($i, num2: 5) * 100.0);
            $c->setStrokeColor(Gray::black())->setLineWidth(0.3)->strokeRect($x, $y, 90.0, 60.0);
            $c->place($image, new Box($x, $y, 90.0, 60.0), Fit::meet());
            $c->setFillColor(Gray::black())->drawText($file, $font, 6.0, $x, $y - 8.0);
        }

        $image = $doc->image(PdfTools::assetPath('images/rgb8.png'));
        foreach (FitMethod::cases() as $i => $method) {
            $x = 30.0 + ($i * 90.0);
            $c->setStrokeColor(Rgb::fromHex('#c2185b'))->setLineWidth(0.5)->strokeRect($x, 230.0, 60.0, 90.0);
            $c->place($image, new Box($x, 230.0, 60.0, 90.0), new Fit($method, Position::topLeft()));
            $c->setFillColor(Gray::black())->drawText($method->name, $font, 6.0, $x, 222.0);
        }
        foreach ([0.0, 30.0, 90.0, 180.0, -45.0] as $i => $degrees) {
            $x = 30.0 + ($i * 100.0);
            $c->setStrokeColor(Gray::black())->setLineWidth(0.3)->strokeRect($x, 60.0, 80.0, 80.0);
            $c->place($image, new Box($x, 60.0, 80.0, 80.0), Fit::meet(Position::bottomRight(), $degrees));
        }

        $top = $doc->addPage(PageSize::A4, topDown: true)->canvas();
        $top->setStrokeColor(Gray::black())->setLineWidth(0.5)->strokeRect(40.0, 40.0, 200.0, 120.0);
        $top->place($image, new Box(40.0, 40.0, 200.0, 120.0), Fit::meet(Position::topLeft()));
        $top->place($image, new Box(300.0, 40.0, 200.0, 120.0), Fit::slice(Position::center(), 30.0));
        $top->place(
            $doc->image(PdfTools::assetPath('images/rgba8.png')),
            new Box(40.0, 200.0, 100.0, 100.0),
            Fit::entire(),
        );
        $doc->close();

        PdfTools::assertValid($path);
        $labels = PdfTools::text($path, 1);
        foreach ([
            ...self::FILES,
            ...array_map(static fn(FitMethod $m): string => $m->name, FitMethod::cases()),
        ] as $label) {
            self::assertStringContainsString($label, $labels);
        }
        Visual::assertMatchesBaseline($path, 'image-sheet-p1', 1);
        Visual::assertMatchesBaseline($path, 'image-sheet-p2', 2);
    }
}
