<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Import;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Import\HiddenContent;
use Php2Pdf\Import\HiddenLayers;
use Php2Pdf\Import\PdfFile;
use Php2PdfTest\Support\LayeredPdf;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_replace;
use function str_contains;
use function trim;

/** Removing hidden layers cuts their content out of imported pages (#21). */
#[Group('unit')]
final class HiddenContentTest extends TestCase
{
    private const string FORM_RESOURCES = '/XObject << /Fm0 8 0 R >> /Properties << /Art 5 0 R /Guides 6 0 R >>';

    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function contentProvider(): array
    {
        return [
            'a hidden section'      => [
                '/OC /Art BDC 1 g EMC /OC /Guides BDC 2 g EMC',
                LayeredPdf::RESOURCES,
                '/OC /Art BDC 1 g EMC',
            ],
            'nested marked content' => ['/OC /Guides BDC /Span BMC 1 g EMC 2 g EMC 3 g', LayeredPdf::RESOURCES, '3 g'],
            'an inline image'       => [
                "/OC /Guides BDC BI /W 1 /H 1 ID EMC\nEI EMC 3 g",
                LayeredPdf::RESOURCES,
                '3 g',
            ],
            'a hidden form'         => ['/Fm0 Do 3 g', self::FORM_RESOURCES, '3 g'],
            'an unclosed section'   => ['1 g /OC /Guides BDC 2 g', LayeredPdf::RESOURCES, '1 g'],
            'any-on membership'     => [
                '/OC << /Type /OCMD /OCGs [5 0 R 6 0 R] >> BDC 1 g EMC',
                '',
                '/OC << /Type /OCMD /OCGs [5 0 R 6 0 R] >> BDC 1 g EMC',
            ],
            'all-on membership'     => [
                '/OC << /Type /OCMD /OCGs [5 0 R 6 0 R] /P /AllOn >> BDC 1 g EMC 3 g',
                '',
                '3 g',
            ],
            'all-off membership'    => [
                '/OC << /Type /OCMD /OCGs 6 0 R /P /AllOff >> BDC 1 g EMC',
                '',
                '/OC << /Type /OCMD /OCGs 6 0 R /P /AllOff >> BDC 1 g EMC',
            ],
            'other marked content'  => [
                '/Span << /ActualText (x) >> BDC 1 g EMC',
                '',
                '/Span << /ActualText (x) >> BDC 1 g EMC',
            ],
        ];
    }

    private static function normalise(string $content): string
    {
        return trim(preg_replace('/\s+/', replacement: ' ', subject: $content) ?? '');
    }

    #[Test]
    public function aBaseStateOfOffHidesGroupsNotListedOn(): void
    {
        $file = PdfFile::fromData(LayeredPdf::make('<< /BaseState /OFF /ON [5 0 R] >>'));
        $page = $file->page(1);

        self::assertSame(
            '/OC /Art BDC 0 1 1 0 k 0 0 50 50 re f EMC',
            self::normalise(HiddenContent::of($file->reader())?->filter($page->content(), $page->resources()) ?? ''),
        );
    }

    #[Test]
    public function aFileWithNothingHiddenNeedsNoFilter(): void
    {
        self::assertNull(HiddenContent::of(PdfFile::fromData(LayeredPdf::make('<< >>'))->reader()));
    }

    #[Test]
    #[DataProvider('contentProvider')]
    public function cutsHiddenContent(string $content, string $resources, string $expected): void
    {
        $form = LayeredPdf::stream('0 g', '/Type /XObject /Subtype /Form /BBox [0 0 1 1] /OC 6 0 R');
        $file = PdfFile::fromData(LayeredPdf::make(LayeredPdf::GUIDES_OFF, $content, $resources, [$form]));
        $page = $file->page(1);

        self::assertSame(
            $expected,
            self::normalise(HiddenContent::of($file->reader())?->filter($page->content(), $page->resources()) ?? ''),
        );
    }

    #[Test]
    public function hiddenContentInsideFormsIsRemovedToo(): void
    {
        $form = LayeredPdf::stream(
            '/OC /Art BDC 0.125 g EMC /OC /Guides BDC 0.875 g EMC',
            '/Type /XObject /Subtype /Form /BBox [0 0 1 1] /Resources << /Properties << /Art 5 0 R /Guides 6 0 R >> >>',
        );
        $pdf = $this->import(LayeredPdf::make(LayeredPdf::GUIDES_OFF, '/Fm0 Do', '/XObject << /Fm0 8 0 R >>', [$form]));

        self::assertSame([true, false], [str_contains($pdf, '0.125 g'), str_contains($pdf, '0.875 g')]);
    }

    #[Test]
    public function removedLayersLeaveTheLayersPanel(): void
    {
        $reader     = PdfFile::fromData($this->import(LayeredPdf::make()))->reader();
        $properties = $reader->dict($reader->catalog(), 'OCProperties');
        self::assertNotNull($properties);
        $names = [];
        foreach ((array) $reader->get($properties, 'OCGs') as $group) {
            $names[] = $reader->resolve($group)?->get('Name');
        }

        self::assertSame(['Artwork'], $names);
    }

    private function import(string $source): string
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(compress: false));
        $doc->addPage(PageSize::A4)->place(
            $doc->import(PdfFile::fromData($source), HiddenLayers::Remove)->page(1),
            new Box(0.0, 0.0, 100.0, 100.0),
        );
        $doc->close();

        return $out->contents();
    }
}
