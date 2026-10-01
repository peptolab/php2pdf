<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Canvas;

use PhpPdf\Canvas\Canvas;
use PhpPdf\Canvas\PlaceableInterface;
use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Dimensions;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\PageSize;
use PhpPdf\Fit\Fit;
use PhpPdf\Geometry\Box;
use PhpPdf\Geometry\Matrix;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Artwork of your own can be placed like an image or a template (#28). */
#[Group('unit')]
final class CustomPlaceableTest extends TestCase
{
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
