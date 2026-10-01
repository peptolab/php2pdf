<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Canvas;

use PhpPdf\Canvas\SoftMaskType;
use PhpPdf\Color\Gray;
use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Dimensions;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\PageSize;
use PhpPdf\Exception\InvalidArgumentException;
use PhpPdf\Fit\Fit;
use PhpPdf\Geometry\Box;
use PhpPdf\Svg\SvgFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match;

/** Soft masks on the canvas and from SVG <mask> (#37). */
#[Group('unit')]
final class SoftMaskTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;

    /** @return array<string, array{0: string, 1: string}> */
    public static function svgProvider(): array
    {
        return [
            'luminance' => ['', 'Luminosity'],
            'alpha'     => [' mask-type="alpha"', 'Alpha'],
        ];
    }

    /** @return array<string, array{0: SoftMaskType, 1: string}> */
    public static function typeProvider(): array
    {
        return ['luminosity' => [SoftMaskType::Luminosity, 'Luminosity'], 'alpha' => [SoftMaskType::Alpha, 'Alpha']];
    }

    #[Test]
    public function aMaskFromAnotherDocumentIsRefused(): void
    {
        $other = (new Document(new MemoryOutput()))->template(new Dimensions(10.0, 10.0));

        $this->expectException(InvalidArgumentException::class);
        $this->doc->addPage(PageSize::A4)->canvas()->setSoftMask($other);
    }

    #[Test]
    #[DataProvider('svgProvider')]
    public function anSvgMaskIsSetBeforeTheElement(string $type, string $subtype): void
    {
        $svg = SvgFile::fromData(
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10">'
                . "<mask id=\"m\"{$type}><rect width=\"5\" height=\"10\" fill=\"white\"/></mask>"
                . '<rect width="10" height="10" fill="red" mask="url(#m)"/></svg>',
        );
        $this->doc->addPage(PageSize::A4)->place($this->doc->svg($svg), new Box(0.0, 0.0, 100.0, 100.0), Fit::entire());
        $this->doc->close();
        $pdf = $this->out->contents();

        self::assertSame(
            [1, 1],
            [
                preg_match("#/SMask<</Type/Mask/S/{$subtype}/G#", $pdf),
                preg_match('#/GS\d+ gs\n1 0 0 rg\n0 0 m\n#', $pdf),
            ],
        );
    }

    #[Test]
    #[DataProvider('typeProvider')]
    public function aTemplateMasksWhatIsDrawnNext(SoftMaskType $type, string $subtype): void
    {
        $mask = $this->doc->template(new Dimensions(100.0, 100.0));
        $mask->canvas()->setFillColor(Gray::white())->fillRect(0.0, 0.0, 50.0, 100.0);
        $this->doc->addPage(PageSize::A4)->canvas()->setSoftMask($mask, $type)->fillRect(0.0, 0.0, 100.0, 100.0);
        $this->doc->close();
        $pdf = $this->out->contents();

        self::assertSame(
            [1, 1],
            [
                preg_match("#/SMask<</Type/Mask/S/{$subtype}/G \\d+ 0 R>>#", $pdf),
                preg_match('#/Group<</S/Transparency#', $pdf),
            ],
        );
    }

    protected function setUp(): void
    {
        $this->out = new MemoryOutput();
        $this->doc = new Document($this->out, new DocumentOptions(compress: false));
    }
}
