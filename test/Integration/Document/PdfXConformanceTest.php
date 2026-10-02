<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Document;

use Php2Pdf\Color\Cmyk;
use Php2Pdf\Color\ColorInterface;
use Php2Pdf\Color\GradientStop;
use Php2Pdf\Color\Gray;
use Php2Pdf\Color\IccProfile;
use Php2Pdf\Color\LinearGradient;
use Php2Pdf\Color\Rgb;
use Php2Pdf\Color\Spot;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Document\PdfX;
use Php2Pdf\Exception\ConformanceException;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Geometry\Box;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function str_pad;
use function str_repeat;
use function trim;

/** PDF/X-4 checks colour spaces against the output intent, imported pages, page boxes and XMP (#18). */
#[Group('integration')]
final class PdfXConformanceTest extends TestCase
{
    private MemoryOutput $out;

    /** @return array<string, array{0: string, 1: string|null, 2: ColorInterface, 3: string|null}> */
    public static function colourPolicyProvider(): array
    {
        $rgb  = Rgb::fromHex('#ff0000');
        $cmyk = Cmyk::fromPercent(0, 100, 100, 0);

        return [
            'grey under CMYK'               => ['CMYK', null, Gray::black(), null],
            'grey under RGB'                => ['RGB ', null, Gray::black(), null],
            'CMYK under CMYK'               => ['CMYK', null, $cmyk, null],
            'RGB under RGB'                 => ['RGB ', null, $rgb, null],
            'RGB under CMYK with a profile' => ['CMYK', 'RGB ', $rgb, null],
            'RGB under CMYK'                => ['CMYK', null, $rgb, 'needs an RGB profile'],
            'RGB under grey'                => ['GRAY', null, $rgb, 'with a grey output intent needs an RGB profile'],
            'CMYK under RGB'                => [
                'RGB ',
                null,
                $cmyk,
                'with an RGB output intent cannot use DeviceCMYK colours',
            ],
            'spot with an RGB alternate'    => ['CMYK', null, new Spot('Brand', $rgb), 'needs an RGB profile'],
            'spot with a CMYK alternate'    => ['RGB ', null, new Spot('Brand', $cmyk), 'cannot use DeviceCMYK'],
        ];
    }

    private static function profile(string $space): IccProfile
    {
        return new IccProfile(
            str_pad(str_repeat("\0", times: 16) . $space, length: 128, pad_string: "\0"),
            'test-' . trim($space),
        );
    }

    #[Test]
    public function aBoxThatBreaksTheRulesIsNotKept(): void
    {
        $page = $this->document('CMYK')
            ->addPage(PageSize::A4)
            ->setBleedBox(new Box(20.0, 20.0, 100.0, 100.0));
        try {
            $page->setTrimBox(new Box(10.0, 10.0, 100.0, 100.0));
            self::fail('Expected the TrimBox to be refused.');
        } catch (ConformanceException) {
            self::assertEquals($page->mediaBox(), $page->trimBox());
        }
    }

    #[Test]
    public function aPageMayNotHaveBothATrimBoxAndAnArtBox(): void
    {
        $page = $this->document('CMYK')
            ->addPage(PageSize::A4)
            ->setTrimBox(new Box(10.0, 10.0, 100.0, 100.0));

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('PDF/X-4 page 1 has both a TrimBox and an ArtBox');
        $page->setArtBox(new Box(10.0, 10.0, 100.0, 100.0));
    }

    #[Test]
    public function aPageThatFailsIsLeftUnwrittenAndOpen(): void
    {
        $doc  = $this->document('CMYK');
        $page = $doc->addPage(PageSize::A4)->addLink(new Box(0.0, 0.0, 20.0, 10.0), 'https://example.com/');
        $page->canvas()->fillRect(100.0, 100.0, 50.0, 50.0);
        $written = $this->out->contents();
        try {
            $doc->addPage(PageSize::A4);
            self::fail('Expected page 1 to be refused.');
        } catch (ConformanceException $e) {
            self::assertStringContainsString('PDF/X-4 page 1: a link at [0 0 20 10]', $e->getMessage());
        }
        self::assertSame($written, $this->out->contents());

        $page->setCropBox(new Box(30.0, 30.0, 500.0, 700.0))
            ->canvas()
            ->fillRect(200.0, 200.0, 50.0, 50.0);
        $doc->close();
        self::assertStringContainsString("100 100 50 50 re\nf\n200 200 50 50 re\nf", $this->out->contents());
    }

    #[Test]
    #[DataProvider('colourPolicyProvider')]
    public function deviceColoursMustSuitTheOutputIntent(
        string $intent,
        ?string $rgbProfile,
        ColorInterface $color,
        ?string $error,
    ): void {
        $doc = $this->document($intent, $rgbProfile);
        if (null !== $error) {
            $this->expectException(PdfException::class);
            $this->expectExceptionMessage($error);
        }
        $doc->addPage(PageSize::A4)->canvas()->setFillColor($color);

        $this->addToAssertionCount(1);
    }

    #[Test]
    public function gradientsAreCheckedLikeColours(): void
    {
        $doc      = $this->document('RGB ');
        $gradient = new LinearGradient(0, 0, 10, 0, [
            new GradientStop(0.0, Cmyk::fromPercent(0, 0, 0, 0)),
            new GradientStop(1.0, Cmyk::fromPercent(0, 0, 0, 100)),
        ]);

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('cannot use DeviceCMYK gradients');
        $doc->addPage(PageSize::A4)->canvas()->setFillGradient($gradient);
    }

    #[Test]
    public function imagesAreCheckedWhenDrawn(): void
    {
        $doc   = $this->document('CMYK');
        $image = $doc->image(PdfTools::assetPath('images/rgb8.png'));

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('before RGB images can be used');
        $doc->addPage(PageSize::A4)->place($image, new Box(0.0, 0.0, 100.0, 100.0));
    }

    #[Test]
    public function importedRgbArtworkIsAcceptedUnderAnRgbIntent(): void
    {
        $doc = $this->document('RGB ');
        $doc->addPage(PageSize::A4)->place(
            $doc->import(PdfTools::assetPath('pdf/cairo.pdf'))->page(1),
            new Box(0.0, 0.0, 200.0, 200.0),
        );
        $doc->close();

        self::assertStringContainsString('/Subtype/Form', $this->out->contents());
    }

    #[Test]
    public function importedRgbArtworkIsRefusedUnderACmykIntentEvenWithAnRgbProfile(): void
    {
        $doc  = $this->document('CMYK', rgbProfile: 'RGB ');
        $page = $doc->import(PdfTools::assetPath('pdf/cairo.pdf'))->page(1);

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('cannot place imported page 1: it draws in DeviceRGB');
        $doc->addPage(PageSize::A4)->place($page, new Box(0.0, 0.0, 200.0, 200.0));
    }

    #[Test]
    public function linksAreRefusedWhenAddedOverAPrintedBox(): void
    {
        $page = $this->document('CMYK')
            ->addPage(PageSize::A4)
            ->setTrimBox(new Box(30.0, 30.0, 500.0, 700.0));

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('PDF/X-4 page 1: a link at [100 100 120 110] overlaps the TrimBox');
        $page->addLink(new Box(100.0, 100.0, 20.0, 10.0), 'https://example.com/');
    }

    #[Test]
    public function linksInsideTheTrimBoxAreRefused(): void
    {
        $page = $this->document('CMYK')
            ->addPage(PageSize::A4)
            ->addLink(new Box(0.0, 0.0, 40.0, 40.0), 'https://example.com/');

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('PDF/X-4 page 1: a link at [0 0 40 40] overlaps the TrimBox');
        $page->setTrimBox(new Box(30.0, 30.0, 500.0, 700.0));
    }

    #[Test]
    public function linksOnAPageWithoutPrintedBoxesAreRefused(): void
    {
        $doc = $this->document('CMYK');
        $doc->addPage(PageSize::A4)->addLink(new Box(0.0, 0.0, 20.0, 10.0), 'https://example.com/');

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('overlaps the TrimBox');
        $doc->close();
    }

    #[Test]
    public function linksOnATopDownPageAreCheckedInPageCoordinates(): void
    {
        $doc = $this->document('CMYK');
        $doc->addPage(PageSize::A4, topDown: true)
            ->setTrimBox(new Box(30.0, 30.0, 500.0, 700.0))
            ->addLink(new Box(100.0, 750.0, 20.0, 10.0), 'https://example.com/');
        $doc->close();

        self::assertStringContainsString('/Subtype/Link', $this->out->contents());
    }

    #[Test]
    public function linksOutsideTheBleedBoxAreAllowed(): void
    {
        $doc = $this->document('CMYK');
        $doc->addPage(PageSize::A4)
            ->addLink(new Box(0.0, 0.0, 20.0, 10.0), 'https://example.com/')
            ->setBleedBox(new Box(30.0, 30.0, 500.0, 700.0))
            ->setTrimBox(new Box(40.0, 40.0, 480.0, 680.0));
        $doc->close();

        self::assertStringContainsString('/Subtype/Link', $this->out->contents());
    }

    #[Test]
    public function nestedBoxesAndXmpVersionFieldsAreWritten(): void
    {
        $doc = $this->document('CMYK');
        $doc->addPage(PageSize::A4)
            ->setBleedBox(new Box(5.0, 5.0, 200.0, 200.0))
            ->setTrimBox(new Box(10.0, 10.0, 100.0, 100.0));
        $doc->close();

        self::assertStringContainsString(
            '<xmpMM:VersionID>1</xmpMM:VersionID><xmpMM:RenditionClass>default</xmpMM:RenditionClass>',
            $this->out->contents(),
        );
    }

    #[Test]
    public function theTrimBoxMustLieWithinTheBleedBox(): void
    {
        $page = $this->document('CMYK')
            ->addPage(PageSize::A4)
            ->setBleedBox(new Box(20.0, 20.0, 100.0, 100.0));

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('page 1: the TrimBox must lie within the BleedBox');
        $page->setTrimBox(new Box(10.0, 10.0, 100.0, 100.0));
    }

    private function document(string $intent, ?string $rgbProfile = null): Document
    {
        $this->out = new MemoryOutput();
        $doc       = new Document($this->out, new DocumentOptions(
            compress: false,
            clock: new FixedClock('2026-01-01T00:00:00Z'),
            pdfx: PdfX::X4,
            rgbProfile: null === $rgbProfile ? null : self::profile($rgbProfile),
        ));
        $doc->setOutputIntent(self::profile($intent), 'Test condition');
        $doc->info()->setTitle('PDF/X test');

        return $doc;
    }
}
