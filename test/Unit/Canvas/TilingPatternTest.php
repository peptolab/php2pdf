<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Canvas;

use Php2Pdf\Color\Gray;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Dimensions;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Geometry\Matrix;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function preg_match_all;

/** Tiling patterns on the canvas (#37). */
#[Group('unit')]
final class TilingPatternTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;

    /** @return array<string, array{0: float, 1: float}> */
    public static function zeroStepProvider(): array
    {
        return ['x' => [0.0, 10.0], 'y' => [10.0, 0.0]];
    }

    #[Test]
    public function aFillPatternDrawsTheTemplateInEachCell(): void
    {
        $tile = $this->doc->template(new Dimensions(10.0, 10.0));
        $tile->canvas()->setFillColor(Gray::black())->fillRect(0.0, 0.0, 5.0, 5.0);
        $this->doc
            ->addPage(PageSize::A4)
            ->canvas()
            ->setFillPattern($tile, 12.0, 10.0)
            ->fillRect(0.0, 0.0, 100.0, 100.0);
        $this->doc->close();
        $pdf = $this->out->contents();

        self::assertSame(
            [1, 1, 1],
            [
                preg_match(
                    '#/PatternType 1/PaintType 1/TilingType 1/BBox\[0 0 10 10\]/XStep 12/YStep 10/Resources<</XObject<</T0 \d+ 0 R>>>>/Matrix\[1 0 0 1 0 0\]#',
                    $pdf,
                ),
                preg_match("#stream\n/T0 Do\n#", $pdf),
                preg_match("#/Pattern cs /P1 scn\n0 0 100 100 re\n#", $pdf),
            ],
        );
    }

    #[Test]
    public function aStrokePatternIsComposedWithTheCurrentTransformation(): void
    {
        $tile = $this->doc->template(new Dimensions(4.0, 4.0));
        $this->doc
            ->addPage(PageSize::A4)
            ->canvas()
            ->translate(50.0, 0.0)
            ->setStrokePattern($tile, 4.0, 4.0, Matrix::scaling(2.0))
            ->strokeRect(0.0, 0.0, 10.0, 10.0);
        $this->doc->close();
        $pdf = $this->out->contents();

        self::assertSame(
            [1, 1],
            [
                preg_match('#/XStep 4/YStep 4/.*/Matrix\[2 0 0 2 50 0\]#', $pdf),
                preg_match("#/Pattern CS /P1 SCN\n#", $pdf),
            ],
        );
    }

    #[Test]
    public function aTemplateFromAnotherDocumentIsRefused(): void
    {
        $other = (new Document(new MemoryOutput()))->template(new Dimensions(10.0, 10.0));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('A template');
        $this->doc->addPage(PageSize::A4)->canvas()->setFillPattern($other, 10.0, 10.0);
    }

    #[Test]
    #[DataProvider('zeroStepProvider')]
    public function aZeroStepIsRefused(float $xStep, float $yStep): void
    {
        $tile = $this->doc->template(new Dimensions(10.0, 10.0));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('non-zero steps');
        $this->doc->addPage(PageSize::A4)->canvas()->setFillPattern($tile, $xStep, $yStep);
    }

    #[Test]
    public function theSamePatternIsWrittenOnce(): void
    {
        $tile   = $this->doc->template(new Dimensions(4.0, 4.0));
        $canvas = $this->doc->addPage(PageSize::A4)->canvas();
        $canvas->setFillPattern($tile, 4.0, 4.0)->fillRect(0.0, 0.0, 10.0, 10.0);
        $canvas->setFillPattern($tile, 4.0, 4.0)->fillRect(20.0, 0.0, 10.0, 10.0);
        $this->doc->close();

        self::assertSame(1, preg_match_all('#/PatternType 1#', $this->out->contents()));
    }

    protected function setUp(): void
    {
        $this->out = new MemoryOutput();
        $this->doc = new Document($this->out, new DocumentOptions(compress: false));
    }
}
