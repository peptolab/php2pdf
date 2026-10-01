<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Canvas;

use PhpPdf\Color\GradientStop;
use PhpPdf\Color\Gray;
use PhpPdf\Color\LinearGradient;
use PhpPdf\Core\FixedClock;
use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Dimensions;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\Page;
use PhpPdf\Font\CoreFont;
use PhpPdf\Font\Glyph;
use PhpPdf\Font\GlyphRun;
use PhpPdf\Font\StandardFont;
use PhpPdf\Font\TextOptions;
use PhpPdf\Geometry\Box;
use PhpPdf\Geometry\Matrix;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function end;
use function preg_match_all;
use function substr_count;

/**
 * Text is written with only the text state and fill colour that changed since the last text,
 * positioned with Td when the text matrix is a translation, and shown as literal strings.
 */
#[Group('unit')]
final class CanvasTextTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;
    private Page $page;
    private CoreFont $helvetica;

    #[Test]
    public function adjustmentsSplitTheStringIntoATjArray(): void
    {
        $this->page->canvas()->drawText('AV', $this->helvetica, 10.0, 0.0, 0.0);

        self::assertStringContainsString('[(A) 70 (V)] TJ', $this->content());
    }

    #[Test]
    public function aGradientFillMakesTheFillColourUnknown(): void
    {
        $gradient = new LinearGradient(0.0, 0.0, 10.0, 0.0, [
            new GradientStop(0.0, Gray::black()),
            new GradientStop(1.0, Gray::white()),
        ]);
        $this->page
            ->canvas()
            ->setFillColor(Gray::black())
            ->setFillGradient($gradient)
            ->setFillColor(Gray::black());

        self::assertSame(1, substr_count($this->content(), needle: "\n0 g\n"));
    }

    #[Test]
    public function anUnchangedFillColourIsNotWrittenAgain(): void
    {
        $this->page
            ->canvas()
            ->setFillColor(new Gray(0.5))
            ->setFillColor(new Gray(0.5))
            ->setFillColor(Gray::black());

        self::assertSame("0.5 g\n0 g\n", $this->content());
    }

    #[Test]
    public function aPageStartsFromTheDefaultTextState(): void
    {
        $this->page->canvas()->drawText('a', $this->helvetica, 10.0, 0.0, 0.0);

        self::assertSame("BT /F1 10 Tf 0 0 Td (a) Tj ET\n", $this->content());
    }

    #[Test]
    public function aTemplateCannotAssumeTheDefaultTextState(): void
    {
        $template = $this->doc->template(new Dimensions(100.0, 100.0));
        $template->canvas()->drawText('a', $this->helvetica, 10.0, 0.0, 0.0);
        $this->page->canvas()->place($template, new Box(0.0, 0.0, 100.0, 100.0));
        $this->doc->close();

        self::assertStringContainsString(
            'BT /F1 10 Tf 0 Tc 100 Tz 0 Ts 0 Tr 0 0 Td (a) Tj ET',
            $this->out->contents(),
        );
    }

    #[Test]
    public function aTransformedTextMatrixIsWrittenWithTm(): void
    {
        $this->page->canvas()->showRun(
            $this->helvetica->shape('a'),
            $this->helvetica,
            10.0,
            new Matrix(0.0, 1.0, -1.0, 0.0, 5.0, 6.0),
            TextOptions::default(),
        );

        self::assertSame("BT /F1 10 Tf 0 1 -1 0 5 6 Tm (a) Tj ET\n", $this->content());
    }

    #[Test]
    public function blackNeedsNoOperatorOnAFreshPage(): void
    {
        $this->page->canvas()->setFillColor(Gray::black());

        self::assertSame('', $this->content());
    }

    #[Test]
    public function rawSyntaxMakesTheTextStateUnknown(): void
    {
        $this->page
            ->canvas()
            ->drawText('a', $this->helvetica, 10.0, 0.0, 0.0)
            ->raw('2 Tc')
            ->drawText('b', $this->helvetica, 10.0, 0.0, 20.0);

        self::assertStringEndsWith("2 Tc\nBT /F1 10 Tf 0 Tc 100 Tz 0 Ts 0 Tr 0 20 Td (b) Tj ET\n", $this->content());
    }

    #[Test]
    public function restoreBringsBackTheSavedTextState(): void
    {
        $this->page
            ->canvas()
            ->save()
            ->drawText('a', $this->helvetica, 10.0, 0.0, 0.0, new TextOptions(rise: 3.0))
            ->restore()
            ->drawText('b', $this->helvetica, 10.0, 0.0, 20.0);

        self::assertSame(
            "q\nBT /F1 10 Tf 3 Ts 0 0 Td (a) Tj ET\nQ\nBT /F1 10 Tf 0 20 Td (b) Tj ET\n",
            $this->content(),
            'After Q the rise is back to 0 but the font is unset again.',
        );
    }

    #[Test]
    public function stringDelimitersBackslashesAndCarriageReturnsAreEscaped(): void
    {
        $run = new GlyphRun([
            new Glyph(0x28, 0x28, 0),
            new Glyph(0x29, 0x29, 0),
            new Glyph(0x5C, 0x5C, 0),
            new Glyph(0x0D, 0x0D, 0),
        ]);
        $this->page->canvas()->showRun($run, $this->helvetica, 10.0, Matrix::identity(), TextOptions::default());

        self::assertStringContainsString('(\\(\\)\\\\\\r) Tj', $this->content());
    }

    #[Test]
    public function unchangedTextStateIsNotWrittenAgain(): void
    {
        $spaced = new TextOptions(charSpacing: 1.0);
        $this->page
            ->canvas()
            ->drawText('a', $this->helvetica, 10.0, 0.0, 0.0, $spaced)
            ->drawText('b', $this->helvetica, 10.0, 0.0, 20.0, $spaced)
            ->drawText('c', $this->helvetica, 10.0, 0.0, 40.0);

        self::assertSame(
            "BT /F1 10 Tf 1 Tc 0 0 Td (a) Tj ET\nBT 0 20 Td (b) Tj ET\nBT 0 Tc 0 40 Td (c) Tj ET\n",
            $this->content(),
        );
    }

    protected function setUp(): void
    {
        $this->out = new MemoryOutput();
        $this->doc = new Document($this->out, new DocumentOptions(
            clock: new FixedClock('2026-01-01'),
            compress: false,
            xmp: false,
        ));
        $this->page      = $this->doc->addPage();
        $this->helvetica = $this->doc->fonts()->standard(StandardFont::Helvetica);
    }

    private function content(): string
    {
        $this->doc->close();
        self::assertGreaterThan(0, preg_match_all('/stream\n(.*?)\nendstream/s', $this->out->contents(), $m));

        return (string) end($m[1]);
    }
}
