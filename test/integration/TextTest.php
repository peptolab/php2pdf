<?php

declare(strict_types=1);

namespace PhpPdfTest\Integration;

use PhpPdf\Color\Gray;
use PhpPdf\Core\FixedClock;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\PageSize;
use PhpPdf\Font\TextOptions;
use PhpPdf\Font\TextRenderMode;
use PhpPdf\Geometry\Box;
use PhpPdf\Text\Style\TextStyle;
use PhpPdfTest\Support\PdfTools;
use PhpPdfTest\Support\Visual;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class TextTest extends TestCase
{
    #[Test]
    public function embeddedTextRendersAndCopiesOut(): void
    {
        $path = PdfTools::outputDir() . '/text.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $sans = $doc->fonts()->load(PdfTools::assetPath('fonts/SourceSans3-Regular.otf'));
        $bold = $doc->fonts()->load(PdfTools::assetPath('fonts/SourceSans3-Bold.otf'));

        $c = $doc->addPage(PageSize::A4)->canvas();
        $c->drawText('AVATAR To Wave — kerned', $sans, 24, 50, 760);
        $c->drawText('AVATAR To Wave — unkerned', $sans, 24, 50, 720, new TextOptions(kerning: false));
        $c->drawText('Bold with ÄÖÜ ß € and fi fl', $bold, 24, 50, 680);
        $c->drawText('Tracking and word spacing', $sans, 14, 50, 640, new TextOptions(
            tracking: 100,
            wordSpacing: 10,
        ));
        $c->setLineWidth(0.8)->drawText(
            'Outlined',
            $sans,
            40,
            50,
            580,
            new TextOptions(renderMode: TextRenderMode::Stroke),
        );
        $c->setFillColor(new Gray(0.4))->drawText('Rise and 70% scale', $sans, 14, 50, 540, new TextOptions(
            rise: 4,
            horizontalScale: 70,
        ));

        $width = $sans->width('AVATAR To Wave — kerned', 24);
        $c->setStrokeColor(Gray::black())
            ->setLineWidth(0.5)
            ->line(50, 755, 50 + $width, 755);

        $doc->addPage(PageSize::A4, topDown: true)->canvas()->drawText('Top-down text', $sans, 18, 50, 80);
        $doc->close();

        PdfTools::assertValid($path);
        $text = PdfTools::text($path);
        self::assertStringContainsString('AVATAR To Wave — kerned', $text);
        self::assertStringContainsString('Bold with ÄÖÜ ß € and fi fl', $text);
        self::assertStringContainsString('Top-down text', PdfTools::text($path, 2));
        Visual::assertMatchesBaseline($path, 'text-p1', 1);
        Visual::assertMatchesBaseline($path, 'text-p2', 2);
    }

    /** A paragraph mixing fonts through a fallback stack renders and copies out whole (#22). */
    #[Test]
    public function fallbackFontsSetWhatTheFontLacks(): void
    {
        $path   = PdfTools::outputDir() . '/text-fallback.pdf';
        $doc    = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $dejaVu = $doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $flow   = $doc->textflow(
            'Snow ☃ in Ω-band weather, with Helvetica for the Latin text.',
            new TextStyle(
                font: 'Helvetica',
                size: 14.0,
                fallback: [$dejaVu],
            ),
        );
        $doc->addPage(PageSize::A4)->fitTextflow($flow, new Box(72.0, 600.0, 400.0, 100.0));
        $doc->close();

        PdfTools::assertValid($path);
        self::assertStringContainsString(
            'Snow ☃ in Ω-band weather, with Helvetica for the Latin text.',
            PdfTools::text($path),
        );
    }
}
