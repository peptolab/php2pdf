<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Canvas;

use Override;
use Php2Pdf\Canvas\TextlineOptions;
use Php2Pdf\Color\GradientStop;
use Php2Pdf\Color\Gray;
use Php2Pdf\Color\LinearGradient;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\Page;
use Php2Pdf\Fit\FitMethod;
use Php2Pdf\Font\CoreFont;
use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Font\StandardFont;
use Php2Pdf\Font\TextOptions;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Geometry\Matrix;
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
    public function aClippedTextlineIsClippedToItsBox(): void
    {
        $this->page
            ->canvas()
            ->fitTextline(
                'Clipped',
                $this->helvetica,
                10.0,
                new Box(10.0, 20.0, 15.0, 8.0),
                new TextlineOptions(method: FitMethod::Clip),
            );

        self::assertStringContainsString("10 20 15 8 re\nW n\n", $this->content());
    }

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
    public function emptyTextDrawsNothing(): void
    {
        $this->page->canvas()->drawText('', $this->helvetica, 10.0, 0.0, 0.0);

        self::assertSame('', $this->content());
    }

    #[Test]
    public function equalOptionsInAnotherObjectWriteNoTextState(): void
    {
        $this->page
            ->canvas()
            ->drawText('a', $this->helvetica, 10.0, 0.0, 0.0, new TextOptions(horizontalScale: 90.0))
            ->drawText('b', $this->helvetica, 10.0, 0.0, 20.0, new TextOptions(horizontalScale: 90.0))
            ->drawText('c', $this->helvetica, 10.0, 0.0, 40.0, new TextOptions(
                horizontalScale: 80.0,
                rise: 1.0,
            ));

        self::assertSame(
            "BT /F1 10 Tf 90 Tz 0 0 Td (a) Tj ET\nBT 0 20 Td (b) Tj ET\nBT 80 Tz 1 Ts 0 40 Td (c) Tj ET\n",
            $this->content(),
        );
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
    public function restoreBringsBackTheSavedFillColour(): void
    {
        $grey = new Gray(0.5);
        $this->page
            ->canvas()
            ->setFillColor($grey)
            ->save()
            ->setFillColor(Gray::black())
            ->restore()
            ->setFillColor($grey)
            ->setFillColor(Gray::black())
            ->setFillColor($grey)
            ->raw('1 g')
            ->setFillColor($grey);

        self::assertSame("0.5 g\nq\n0 g\nQ\n0 g\n0.5 g\n1 g\n0.5 g\n", $this->content());
    }

    #[Test]
    public function restoreBringsBackTheSavedFontSize(): void
    {
        $this->page
            ->canvas()
            ->drawText('a', $this->helvetica, 10.0, 0.0, 0.0)
            ->save()
            ->drawText('b', $this->helvetica, 12.0, 0.0, 20.0)
            ->restore()
            ->drawText('c', $this->helvetica, 10.0, 0.0, 40.0)
            ->drawText('d', $this->helvetica, 12.0, 0.0, 60.0);

        self::assertSame(
            "BT /F1 10 Tf 0 0 Td (a) Tj ET\nq\nBT /F1 12 Tf 0 20 Td (b) Tj ET\nQ\n"
                . "BT 0 40 Td (c) Tj ET\nBT /F1 12 Tf 0 60 Td (d) Tj ET\n",
            $this->content(),
            'Q brings back the 10 point Tf, so c needs none; the 12 point one must be written again for d.',
        );
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

    #[Override]
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
        $m = [];
        self::assertGreaterThan(0, preg_match_all('/stream\n(.*?)\nendstream/s', $this->out->contents(), $m));
        $streams = $m[1] ?? null;
        self::assertNotNull($streams);
        $content = end($streams);
        self::assertIsString($content);

        return $content;
    }
}
