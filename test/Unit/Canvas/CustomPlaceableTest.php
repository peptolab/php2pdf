<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Canvas;

use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Canvas\PlaceableInterface;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Dimensions;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Matrix;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function substr_count;

/** Artwork of your own can be placed like an image or a template (#28). */
#[Group('unit')]
final class CustomPlaceableTest extends TestCase
{
    /** Artwork can write its own XObject once through the document's objects and draw it. */
    #[Test]
    public function aCustomPlaceableCanDrawItsOwnFormXObject(): void
    {
        $out  = new MemoryOutput();
        $doc  = new Document($out, new DocumentOptions(compress: false));
        $mark = new class($doc) implements PlaceableInterface {
            public function __construct(
                private readonly Document $doc,
            ) {}

            public function drawAt(Canvas $canvas, Matrix $matrix, Dimensions $size): void
            {
                $form = $this->doc->objects()->shared('mark', static fn(): Stream => new Stream(
                    Dictionary::of([
                        'Type'    => new Name('XObject'),
                        'Subtype' => new Name('Form'),
                        'BBox'    => [0, 0, 10, 10],
                    ]),
                    '0 0 10 10 re f',
                ));
                $canvas->drawXObject($form, $matrix);
            }

            public function naturalSize(?float $dpi = null): Dimensions
            {
                return new Dimensions(10.0, 10.0);
            }
        };
        $page = $doc->addPage(PageSize::A4);
        $page->place($mark, new Box(0.0, 0.0, 10.0, 10.0));
        $page->place($mark, new Box(50.0, 0.0, 10.0, 10.0));
        $doc->close();
        $pdf = $out->contents();

        self::assertSame([1, 2], [substr_count($pdf, needle: '/Subtype/Form'), substr_count($pdf, needle: ' Do Q')]);
    }

    #[Test]
    public function aCustomPlaceableIsFittedAndDrawnInItsOwnSpace(): void
    {
        $out    = new MemoryOutput();
        $doc    = new Document($out, new DocumentOptions(compress: false));
        $swatch = new class implements PlaceableInterface {
            public function drawAt(Canvas $canvas, Matrix $matrix, Dimensions $size): void
            {
                $canvas->save()->concat($matrix);
                $canvas->fillRect(0.0, 0.0, $size->width, $size->height);
                $canvas->restore();
            }

            public function naturalSize(?float $dpi = null): Dimensions
            {
                return new Dimensions(20.0, 10.0);
            }
        };
        $doc->addPage(PageSize::A4)->place($swatch, new Box(100.0, 100.0, 40.0, 40.0), Fit::meet());
        $doc->close();

        self::assertStringContainsString("q\n2 0 0 2 100 110 cm\n0 0 20 10 re\nf\nQ", $out->contents());
    }
}
