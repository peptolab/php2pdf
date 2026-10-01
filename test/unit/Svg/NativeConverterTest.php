<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Svg;

use PhpPdf\Core\FixedClock;
use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\PageSize;
use PhpPdf\Fit\Fit;
use PhpPdf\Geometry\Box;
use PhpPdf\Svg\NativeConverter;
use PhpPdf\Svg\SvgFile;
use PhpPdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function gzencode;
use function preg_match;
use function str_contains;
use function substr_count;

final class NativeConverterTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;

    #[Test]
    public function convertsShapesIntoAFormXObjectWithRealPaths(): void
    {
        $svg = SvgFile::fromData(<<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" width="100" height="100" viewBox="0 0 10 10">
              <rect x="1" y="1" width="4" height="4" fill="#ff0000" stroke="blue" stroke-width="0.5" stroke-dasharray="1 0.5"/>
              <circle cx="7" cy="7" r="2" fill="none" stroke="rgb(0,255,0)" opacity="0.5"/>
              <path d="M1 9 L9 9" stroke="black" stroke-linecap="round"/>
            </svg>
            SVG);
        $converter = new NativeConverter();
        $template  = $this->doc->svg($svg, $converter);
        self::assertSame(75.0, $template->naturalSize()->width);
        $page = $this->doc->addPage(PageSize::A4);
        $page->place($template, new Box(100.0, 100.0, 200.0, 200.0), Fit::meet());
        $this->doc->close();
        $pdf = $this->out->contents();

        self::assertStringContainsString('/Subtype/Form', $pdf);
        self::assertStringContainsString('1 0 0 rg', $pdf);
        self::assertStringContainsString('0 0 1 RG', $pdf);
        self::assertStringContainsString('[1 0.5] 0 d', $pdf);
        self::assertStringContainsString('1 J', $pdf);
        self::assertStringContainsString('/Group', $pdf);
        self::assertSame([], $converter->warnings());
    }

    #[Test]
    public function gradientsClipsAndUseResolveThroughDefs(): void
    {
        $svg = SvgFile::fromData(<<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" width="100" height="100">
              <defs>
                <linearGradient id="base"><stop offset="0" stop-color="red"/><stop offset="1" stop-color="blue"/></linearGradient>
                <linearGradient id="g" xlink:href="#base" x2="0" y2="1"/>
                <radialGradient id="r" gradientUnits="userSpaceOnUse" cx="50" cy="50" r="40"><stop offset="0" stop-color="white"/><stop offset="1" stop-color="black"/></radialGradient>
                <clipPath id="c"><rect width="50" height="100"/></clipPath>
                <rect id="box" width="20" height="20" fill="green"/>
              </defs>
              <rect width="100" height="50" fill="url(#g)" clip-path="url(#c)"/>
              <rect y="50" width="100" height="50" fill="url(#r)" stroke="url(#base)" stroke-width="2"/>
              <use xlink:href="#box" x="10" y="10"/>
              <use xlink:href="#box" x="40" y="40" transform="rotate(45)"/>
              <rect width="10" height="10" fill="url(#missing) orange"/>
              <rect width="10" height="10" fill="url(#missing)" mask="url(#m)"/>
            </svg>
            SVG);
        $converter = new NativeConverter();
        $template  = $this->doc->svg($svg, $converter);
        $this->doc->addPage(PageSize::A4)->place($template, new Box(0.0, 0.0, 100.0, 100.0));
        $this->doc->close();
        $pdf = $this->out->contents();

        self::assertSame(2, substr_count($pdf, needle: '/ShadingType 2'));
        self::assertSame(1, substr_count($pdf, needle: '/ShadingType 3'));
        self::assertStringContainsString('/Pattern cs', $pdf);
        self::assertStringContainsString('/Pattern CS', $pdf);
        self::assertStringContainsString('W n', $pdf);
        self::assertSame(2, substr_count($pdf, needle: '0 0.502 0 rg'));
        self::assertStringContainsString('1 0.6471 0 rg', $pdf);
        self::assertSame(['mask'], $converter->warnings());
    }

    #[Test]
    public function readsCompressedSvgz(): void
    {
        $file = SvgFile::fromData((string) gzencode(
            '<svg width="10" height="10"><rect width="10" height="10"/></svg>',
        ));
        self::assertSame('svg', $file->root()->name);
    }

    #[Test]
    public function reportsSkippedFeatures(): void
    {
        $svg = SvgFile::fromData(
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><foreignObject/><text>x<textPath/></text><rect width="1" height="1" filter="url(#f)"/></svg>',
        );
        $converter = new NativeConverter();
        $this->doc->svg($svg, $converter);
        self::assertSame(
            ['<foreignObject>', '<textPath>', 'text without an available font', 'filter'],
            $converter->warnings(),
        );
    }

    #[Test]
    public function sizesComeFromWidthHeightOrViewBox(): void
    {
        self::assertEqualsWithDelta(
            [283.46, 141.73],
            [
                SvgFile::fromData('<svg width="100mm" height="50mm"/>')->size()->width,
                SvgFile::fromData('<svg width="100mm" height="50mm"/>')->size()->height,
            ],
            0.01,
        );
        $viewBoxOnly = SvgFile::fromData('<svg viewBox="0 0 400 200"/>')->size();
        self::assertSame([300.0, 150.0], [$viewBoxOnly->width, $viewBoxOnly->height]);
        $heightOnly = SvgFile::fromData('<svg height="100" viewBox="0 0 4 2"/>')->size();
        self::assertSame([150.0, 75.0], [$heightOnly->width, $heightOnly->height]);
        $default = SvgFile::fromData('<svg/>')->size();
        self::assertSame([225.0, 112.5], [$default->width, $default->height]);
    }

    #[Test]
    public function textIsSetWithEmbeddedFontsAndStaysSearchable(): void
    {
        $this->doc->fonts()->addSearchPath(PdfTools::assetPath('fonts'));
        $fallback = $this->doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $svg      = SvgFile::fromData(<<<'SVG'
            <svg xmlns="http://www.w3.org/2000/svg" width="200" height="50">
              <text x="100" y="30" text-anchor="middle" font-family="DejaVu Sans" font-weight="bold" font-size="16">Hello SVG</text>
              <text x="10" y="45" font-family="serif">fallback</text>
            </svg>
            SVG);
        $converter = new NativeConverter($fallback);
        $template  = $this->doc->svg($svg, $converter);
        $this->doc->addPage(PageSize::A4)->place($template, new Box(0.0, 0.0, 200.0, 50.0));
        $this->doc->close();
        $pdf = $this->out->contents();

        self::assertStringContainsString('/FontFile2', $pdf);
        self::assertSame(1, preg_match('/BT\s+\/F\d+ 16 Tf/', $pdf));
        self::assertStringContainsString('DejaVuSans-Bold', $pdf);
        self::assertSame([], $converter->warnings());
        self::assertFalse(str_contains($pdf, '(Hello SVG)'), 'Text goes out as glyph ids, not literal strings.');
    }

    protected function setUp(): void
    {
        $this->out = new MemoryOutput();
        $this->doc = new Document($this->out, new DocumentOptions(
            compress: false,
            clock: new FixedClock('2026-01-01T00:00:00Z'),
        ));
    }
}
