<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Document;

use Closure;
use PhpPdf\Canvas\PlaceableInterface;
use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Dimensions;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\PageSize;
use PhpPdf\Exception\InvalidArgumentException;
use PhpPdf\Font\FontInterface;
use PhpPdf\Font\StandardFont;
use PhpPdf\Geometry\Box;
use PhpPdf\Svg\SvgFile;
use PhpPdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

/** Resources are drawn only into the document that created them (#19). */
#[Group('unit')]
final class ResourceOwnershipTest extends TestCase
{
    /** @return array<string, array{0: Closure(Document): FontInterface, 1: string}> */
    public static function fontProvider(): array
    {
        return [
            'embedded font' => [
                static fn(Document $doc): FontInterface => $doc->fonts()->load(PdfTools::assetPath(
                    'fonts/DejaVuSans.ttf',
                )),
                'A font belongs to the document that created it',
            ],
            'standard font' => [
                static fn(Document $doc): FontInterface => $doc->fonts()->standard(StandardFont::Helvetica),
                'A font belongs to the document that created it',
            ],
        ];
    }

    /** @return array<string, array{0: Closure(Document): PlaceableInterface}> */
    public static function ownArtworkProvider(): array
    {
        return array_map(static fn(array $case): array => [$case[0]], self::placeableProvider());
    }

    /** @return array<string, array{0: Closure(Document): PlaceableInterface, 1: string}> */
    public static function placeableProvider(): array
    {
        return [
            'image'         => [
                static fn(Document $doc): PlaceableInterface => $doc->image(PdfTools::assetPath('images/rgb8.png')),
                'An image belongs to the document that created it',
            ],
            'template'      => [
                static fn(Document $doc): PlaceableInterface => $doc->template(new Dimensions(10.0, 10.0)),
                'A template belongs to the document that created it',
            ],
            'imported page' => [
                static fn(Document $doc): PlaceableInterface => $doc->import(PdfTools::assetPath(
                    'pdf/cairo.pdf',
                ))->page(1),
                'An imported page belongs to the document that created it',
            ],
            'SVG artwork'   => [
                static fn(Document $doc): PlaceableInterface => $doc->svg(SvgFile::fromData(
                    '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10"/></svg>',
                )),
                'A template belongs to the document that created it',
            ],
        ];
    }

    /** @param Closure(Document): FontInterface $make */
    #[Test]
    #[DataProvider('fontProvider')]
    public function aFontFromAnotherDocumentIsRefused(Closure $make, string $error): void
    {
        $font  = $make(new Document(new MemoryOutput()));
        $other = new Document(new MemoryOutput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($error);
        $other->addPage(PageSize::A4)->canvas()->drawText('Text', $font, 12.0, 10.0, 10.0);
    }

    /** @param Closure(Document): PlaceableInterface $make */
    #[Test]
    #[DataProvider('placeableProvider')]
    public function artworkFromAnotherDocumentIsRefused(Closure $make, string $error): void
    {
        $artwork = $make(new Document(new MemoryOutput()));
        $other   = new Document(new MemoryOutput());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($error);
        $other->addPage(PageSize::A4)->place($artwork, new Box(0.0, 0.0, 100.0, 100.0));
    }

    /** @param Closure(Document): PlaceableInterface $make */
    #[Test]
    #[DataProvider('ownArtworkProvider')]
    public function artworkIsDrawnIntoItsOwnDocument(Closure $make): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(compress: false));
        $doc->addPage(PageSize::A4)->place($make($doc), new Box(0.0, 0.0, 100.0, 100.0));
        $doc->close();

        self::assertStringContainsString(' Do Q', $out->contents());
    }
}
