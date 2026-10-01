<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Document;

use PhpPdf\Color\Cmyk;
use PhpPdf\Color\IccProfile;
use PhpPdf\Color\Rgb;
use PhpPdf\Core\FixedClock;
use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Destination;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\PageSize;
use PhpPdf\Document\PdfX;
use PhpPdf\Exception\PdfException;
use PhpPdf\Geometry\Box;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_pad;
use function str_repeat;
use function substr_count;
use function trim;

final class PdfXTest extends TestCase
{
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

    #[Test]
    public function rejectsLinks(): void
    {
        $doc = new Document(new MemoryOutput(), self::options());
        $this->expectException(PdfException::class);
        $doc->addPage(PageSize::A4)->addLink(new Box(0.0, 0.0, 1.0, 1.0), Destination::page(1));
    }

    #[Test]
    public function rejectsRgbWithoutAProfile(): void
    {
        $doc = new Document(new MemoryOutput(), self::options());
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
