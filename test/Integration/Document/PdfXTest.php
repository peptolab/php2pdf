<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Document;

use Closure;
use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Color\Cmyk;
use Php2Pdf\Color\GradientStop;
use Php2Pdf\Color\IccProfile;
use Php2Pdf\Color\LinearGradient;
use Php2Pdf\Color\Rgb;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Document\PdfX;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Fit\Placement;
use Php2Pdf\Geometry\Box;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_pad;
use function str_repeat;
use function substr_count;
use function trim;

#[Group('integration')]
final class PdfXTest extends TestCase
{
    /** @return array<string, array{0: Closure(Canvas, Document): mixed}> */
    public static function colourUseProvider(): array
    {
        return [
            'fill colour'   => [
                static fn(Canvas $c): Canvas => $c->setFillColor(Cmyk::fromPercent(0, 0, 0, 100)),
            ],
            'stroke colour' => [
                static fn(Canvas $c): Canvas => $c->setStrokeColor(Cmyk::fromPercent(0, 0, 0, 100)),
            ],
            'gradient'      => [
                static fn(Canvas $c): Canvas => $c->setFillGradient(new LinearGradient(
                    0,
                    0,
                    10,
                    0,
                    [
                        new GradientStop(0.0, Cmyk::fromPercent(0, 0, 0, 0)),
                        new GradientStop(1.0, Cmyk::fromPercent(0, 0, 0, 100)),
                    ],
                )),
            ],
            'image'         => [
                static fn(Canvas $c, Document $doc): Placement => $c->place(
                    $doc->image(PdfTools::assetPath('images/cmyk.jpg')),
                    new Box(0.0, 0.0, 100.0, 100.0),
                ),
            ],
        ];
    }

    private static function options(?IccProfile $rgb = null): DocumentOptions
    {
        return new DocumentOptions(
            compress: false,
            clock: new FixedClock('2026-01-01T00:00:00Z'),
            pdfx: PdfX::X4,
            rgbProfile: $rgb,
        );
    }

    private static function profile(string $space): IccProfile
    {
        return new IccProfile(
            str_pad(str_repeat("\0", times: 16) . $space, length: 128, pad_string: "\0"),
            'test-' . trim($space),
        );
    }

    /** @param Closure(Canvas, Document): mixed $use */
    #[Test]
    #[DataProvider('colourUseProvider')]
    public function colourBeforeTheOutputIntentIsRefused(Closure $use): void
    {
        $doc    = new Document(new MemoryOutput(), self::options());
        $canvas = $doc->addPage(PageSize::A4)->canvas();

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('PDF/X-4 needs an output intent before any colour, image or gradient is drawn');
        $use($canvas, $doc);
    }

    #[Test]
    public function rejectsRgbWithoutAProfile(): void
    {
        $doc = new Document(new MemoryOutput(), self::options());
        $doc->setOutputIntent(self::profile('CMYK'), 'FOGRA39');
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('RGB profile');
        $doc->addPage(PageSize::A4)->canvas()->setFillColor(Rgb::fromHex('#ff0000'));
    }

    #[Test]
    public function requiresAnOutputIntentAndATitle(): void
    {
        $doc = new Document(new MemoryOutput(), self::options());
        $doc->addPage(PageSize::A4);
        try {
            $doc->close();
            self::fail('Expected an exception about the output intent.');
        } catch (PdfException $e) {
            self::assertStringContainsString('output intent', $e->getMessage());
        }

        $doc = new Document(new MemoryOutput(), self::options());
        $doc->setOutputIntent(self::profile('CMYK'), 'FOGRA39');
        $doc->addPage(PageSize::A4);
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('title');
        $doc->close();
    }

    #[Test]
    public function theOutputIntentMayBeSetAfterPagesAreAdded(): void
    {
        $doc  = new Document(new MemoryOutput(), self::options());
        $page = $doc->addPage(PageSize::A4);
        $doc->setOutputIntent(self::profile('CMYK'), 'FOGRA39');
        $page->canvas()
            ->setFillColor(Cmyk::fromPercent(0, 0, 0, 100))
            ->fillRect(0, 0, 10, 10);

        self::assertStringContainsString('0 0 0 1 k', $page->canvas()->finish());
    }

    #[Test]
    public function writesTheDeclarationsAndDefaultsPdfX4Needs(): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, self::options(self::profile('RGB ')));
        $doc->info()->setTitle('Print job');
        $doc->setOutputIntent(self::profile('CMYK'), 'FOGRA39', 'Coated FOGRA39 (ISO 12647-2:2004)');
        $page = $doc->addPage(PageSize::A4);
        $page->canvas()
            ->setFillColor(Cmyk::fromPercent(0.0, 100.0, 100.0, 0.0))
            ->fillRect(10.0, 10.0, 50.0, 50.0);
        $page->canvas()->setFillColor(Rgb::fromHex('#336699'))->fillRect(70.0, 10.0, 50.0, 50.0);
        $template = $doc->template(PageSize::A6->dimensions())->asTransparencyGroup();
        $template->canvas()
            ->setFillColor(Cmyk::fromPercent(100.0, 0.0, 0.0, 0.0))
            ->fillRect(0.0, 0.0, 10.0, 10.0);
        $page->place($template, new Box(100.0, 100.0, 100.0, 100.0));
        $doc->close();
        $pdf = $out->contents();

        self::assertStringStartsWith('%PDF-1.6', $pdf);
        self::assertStringContainsString('/GTS_PDFXVersion(PDF/X-4)', $pdf);
        self::assertStringContainsString('/Trapped/False', $pdf);
        self::assertStringContainsString('<pdfxid:GTS_PDFXVersion>PDF/X-4</pdfxid:GTS_PDFXVersion>', $pdf);
        self::assertStringContainsString('<pdf:Trapped>False</pdf:Trapped>', $pdf);
        self::assertStringContainsString('/S/GTS_PDFX/OutputConditionIdentifier(FOGRA39)', $pdf);
        self::assertStringContainsString('/TrimBox[0 0 595.2756 841.8898]', $pdf);
        self::assertSame(
            1,
            substr_count($pdf, needle: '/DefaultRGB') >= 2 ? 1 : 0,
            'Page and template resources carry DefaultRGB.',
        );
        self::assertStringContainsString('/S/Transparency/CS/DeviceCMYK', $pdf);
        self::assertStringNotContainsString('/CS/DeviceRGB', $pdf);
    }
}
