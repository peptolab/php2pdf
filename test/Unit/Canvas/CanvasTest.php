<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Canvas;

use Php2Pdf\Canvas\BlendMode;
use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Canvas\FillRule;
use Php2Pdf\Canvas\LineCap;
use Php2Pdf\Canvas\LineJoin;
use Php2Pdf\Color\Cmyk;
use Php2Pdf\Color\GradientStop;
use Php2Pdf\Color\Gray;
use Php2Pdf\Color\IccColor;
use Php2Pdf\Color\IccProfile;
use Php2Pdf\Color\LinearGradient;
use Php2Pdf\Color\RadialGradient;
use Php2Pdf\Color\Rgb;
use Php2Pdf\Color\Spot;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Exception\StateException;
use Php2Pdf\Geometry\Matrix;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function end;
use function preg_match_all;
use function str_pad;
use function str_repeat;
use function substr_count;

#[Group('unit')]
final class CanvasTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;
    private Canvas $canvas;

    private static function fakeProfile(string $space): string
    {
        return str_pad(str_repeat("\0", times: 16) . $space, length: 128, pad_string: "\0");
    }

    #[Test]
    public function anchorsGradientPatternsInUserSpace(): void
    {
        $gradient = new LinearGradient(0, 0, 100, 0, [
            new GradientStop(0.0, Rgb::fromHex('#ff0000')),
            new GradientStop(0.5, Rgb::fromHex('#00ff00')),
            new GradientStop(1.0, Rgb::fromHex('#0000ff')),
        ]);
        $this->canvas
            ->save()
            ->translate(50, 50)
            ->setFillGradient($gradient)
            ->rect(0, 0, 100, 20)
            ->fill()
            ->restore();
        $this->canvas->paintGradient(RadialGradient::circle(0, 0, 10, [
            new GradientStop(0.0, new Gray(0.0)),
            new GradientStop(1.0, new Gray(1.0)),
        ]));

        self::assertSame("q\n1 0 0 1 50 50 cm\n/Pattern cs /P1 scn\n0 0 100 20 re\nf\nQ\n/Sh1 sh\n", $this->content());
        $pdf = $this->pdf();
        self::assertStringContainsString('/PatternType 2/Shading ', $pdf);
        self::assertStringContainsString('/Matrix[1 0 0 1 50 50]', $pdf);
        self::assertStringContainsString('/ShadingType 2/ColorSpace/DeviceRGB/Function<</FunctionType 3', $pdf);
        self::assertStringContainsString('/Bounds[0.5]/Encode[0 1 0 1]', $pdf);
        self::assertStringContainsString('/ShadingType 3/ColorSpace/DeviceGray/Function<</FunctionType 2', $pdf);
        self::assertStringContainsString('/Coords[0 0 0 0 0 10]', $pdf);
    }

    #[Test]
    public function deduplicatesExtGStates(): void
    {
        $this->canvas->setOpacity(0.5)->setOpacity(0.5)->setBlendMode(BlendMode::Multiply)->enableOverprint();

        self::assertSame("/GS1 gs\n/GS1 gs\n/GS2 gs\n/GS3 gs\n", $this->content());
        $pdf = $this->pdf();
        self::assertSame(1, substr_count($pdf, needle: '/Type/ExtGState/ca 0.5/CA 0.5'));
        self::assertStringContainsString('/BM/Multiply', $pdf);
        self::assertStringContainsString('/op true/OP true/OPM 1', $pdf);
    }

    #[Test]
    public function drawsShapes(): void
    {
        $this->canvas
            ->circle(50, 50, 10)
            ->fill()
            ->roundedRect(0, 0, 100, 50, 10)
            ->stroke()
            ->arc(0, 0, 10, 0, 180)
            ->stroke();

        $ops = $this->content();
        self::assertStringStartsWith("60 50 m\n60 55.5228 55.5228 60 50 60 c\n", $ops);
        self::assertStringContainsString("10 0 m\n90 0 l\n95.5228 0 100 4.4772 100 10 c\n", $ops);
        self::assertStringContainsString("10 0 m\n", $ops);
        self::assertMatchesRegularExpression('/-10 0 c\nS\n$/', $ops);
    }

    #[Test]
    public function emitsDeviceColours(): void
    {
        $this->canvas
            ->setFillColor(new Gray(0.5))
            ->setStrokeColor(Rgb::fromHex('#ff8000'))
            ->setColor(Cmyk::fromPercent(100, 0, 0, 10));

        self::assertSame("0.5 g\n1 0.502 0 RG\n1 0 0 0.1 k\n1 0 0 0.1 K\n", $this->content());
    }

    #[Test]
    public function emitsPathAndPaintingOperators(): void
    {
        $this->canvas
            ->setLineWidth(2.5)
            ->setLineCap(LineCap::Round)
            ->setLineJoin(LineJoin::Bevel)
            ->setMiterLimit(4)
            ->setDash([3, 1], 0.5)
            ->moveTo(10, 10)
            ->lineTo(100, 10)
            ->curveTo(110, 20, 120, 30, 130, 40)
            ->closePath()
            ->stroke()
            ->rect(0, 0, 50, 50)
            ->fill(FillRule::EvenOdd)
            ->rect(0, 0, 50, 50)
            ->clip()
            ->endPath();

        self::assertSame(
            "2.5 w\n1 J\n2 j\n4 M\n[3 1] 0.5 d\n10 10 m\n100 10 l\n110 20 120 30 130 40 c\nh\nS\n0 0 50 50 re\nf*\n0 0 50 50 re\nW n\nn\n",
            $this->content(),
        );
    }

    #[Test]
    public function identityConcatIsOmitted(): void
    {
        $this->canvas->concat(Matrix::identity());

        self::assertSame('', $this->content());
    }

    #[Test]
    public function refusesRestoreWithoutSave(): void
    {
        $this->expectException(StateException::class);
        $this->canvas->restore();
    }

    #[Test]
    public function refusesUnbalancedState(): void
    {
        $this->canvas->save();

        $this->expectException(StateException::class);
        $this->expectExceptionMessage('Unbalanced');
        $this->doc->close();
    }

    #[Test]
    public function registersIccColourSpaces(): void
    {
        $profile = new IccProfile(self::fakeProfile('RGB '), 'test');
        $this->canvas->setFillColor(new IccColor($profile, 0.1, 0.2, 0.3));

        self::assertSame("/CS1 cs 0.1 0.2 0.3 scn\n", $this->content());
        $pdf = $this->pdf();
        self::assertStringContainsString('[/ICCBased ', $pdf);
        self::assertStringContainsString('<</N 3/Length 128>>', $pdf);
    }

    #[Test]
    public function registersSpotColourSpacesOnce(): void
    {
        $spot = new Spot('PANTONE 300 C', new Cmyk(1.0, 0.44, 0.0, 0.0));
        $this->canvas->setFillColor($spot)->setStrokeColor($spot->withTint(0.5));

        self::assertSame("/CS1 cs 1 scn\n/CS1 CS 0.5 SCN\n", $this->content());
        $pdf = $this->pdf();
        self::assertSame(1, substr_count($pdf, needle: '[/Separation /PANTONE#20300#20C /DeviceCMYK <<'));
        self::assertStringContainsString('/C0[0 0 0 0]/C1[1 0.44 0 0]', $pdf);
        self::assertStringContainsString('/ColorSpace<</CS1 ', $pdf);
    }

    #[Test]
    public function tracksTheCtmThroughSaveAndRestore(): void
    {
        $this->canvas->save()->translate(10, 20)->scale(2)->rotate(90);
        $ctm = $this->canvas->ctm();
        self::assertEqualsWithDelta([10.0, 22.0], $ctm->apply(1.0, 0.0), 1e-9);
        $this->canvas->restore();
        self::assertTrue($this->canvas->ctm()->isIdentity());

        self::assertSame("q\n1 0 0 1 10 20 cm\n2 0 0 2 0 0 cm\n0 1 -1 0 0 0 cm\nQ\n", $this->content());
    }

    protected function setUp(): void
    {
        $this->out = new MemoryOutput();
        $this->doc = new Document($this->out, new DocumentOptions(
            clock: new FixedClock('2026-01-01'),
            compress: false,
            xmp: false,
        ));
        $this->canvas = $this->doc->addPage()->canvas();
    }

    private function content(): string
    {
        $this->doc->close();

        // The page content stream is written last, after any shared colour-space streams.
        self::assertGreaterThan(0, preg_match_all('/stream\n(.*?)\nendstream/s', $this->out->contents(), $m));

        return end($m[1]);
    }

    private function pdf(): string
    {
        return $this->out->contents();
    }
}
