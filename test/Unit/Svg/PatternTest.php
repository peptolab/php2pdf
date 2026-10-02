<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Override;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Svg\NativeConverter;
use Php2Pdf\Svg\SvgFile;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function preg_match_all;

/** SVG <pattern> paint servers as tiling patterns (#37). */
#[Group('unit')]
final class PatternTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;

    #[Test]
    public function anEmptyTilePaintsNothingNotTheFallbackColour(): void
    {
        $pdf = $this->convert(
            '<pattern id="p" width="0" height="1"><rect width="2" height="2"/></pattern>',
            '<rect width="10" height="10" fill="url(#p) red"/>',
        );

        self::assertSame([0, 0], [preg_match('#/PatternType 1#', $pdf), preg_match('#1 0 0 rg#', $pdf)]);
    }

    #[Test]
    public function aPatternIsPlacedAtItsOriginThroughTheSvgTransformation(): void
    {
        $pdf = $this->convert(
            '<pattern id="p" patternUnits="userSpaceOnUse" x="2" width="4" height="4"><rect width="2" height="2"/></pattern>',
            '<rect width="10" height="10" fill="url(#p)"/>',
        );

        self::assertSame(1, preg_match('#/Matrix\[0\.75 0 0 -0\.75 1\.5 7\.5\]#', $pdf));
    }

    #[Test]
    public function aPatternThatPaintsItselfIsRejectedAsCircular(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('The SVG refers to "p" from within itself: pattern #p -> pattern #p.');
        $this->convert(
            '<pattern id="p" patternUnits="userSpaceOnUse" width="4" height="4"><rect width="2" height="2" fill="url(#p)"/></pattern>',
            '<rect width="10" height="10" fill="url(#p)"/>',
        );
    }

    #[Test]
    public function aPatternWithoutChildrenInheritsThemThroughHref(): void
    {
        $pdf = $this->convert(
            '<pattern id="base" patternUnits="userSpaceOnUse" width="4" height="4"><rect width="2" height="2" fill="lime"/></pattern>'
                . '<pattern id="wide" href="#base" width="8"/>',
            '<rect width="10" height="10" fill="url(#wide)"/>',
        );

        self::assertSame(
            [1, 1],
            [preg_match('#/XStep 8/YStep 4/#', $pdf), preg_match("#0 1 0 rg\n0 0 m\n#", $pdf)],
        );
    }

    #[Test]
    public function aStrokeTakesAPattern(): void
    {
        $pdf = $this->convert(
            '<pattern id="p" patternUnits="userSpaceOnUse" width="4" height="4"><rect width="2" height="2"/></pattern>',
            '<rect width="10" height="10" fill="none" stroke="url(#p)" stroke-width="2"/>',
        );

        self::assertSame(1, preg_match("#/Pattern CS /P1 SCN\n2 w\n#", $pdf));
    }

    #[Test]
    public function boundingBoxContentUnitsScaleTheChildren(): void
    {
        $pdf = $this->convert(
            '<pattern id="p" width="0.5" height="0.5" patternContentUnits="objectBoundingBox"><rect width="0.25" height="0.25"/></pattern>',
            '<rect width="8" height="6" fill="url(#p)"/>',
        );

        self::assertSame(
            [1, 1],
            [preg_match('#/BBox\[0 0 4 3\]/XStep 4/YStep 3/#', $pdf), preg_match("#\n8 0 0 6 0 0 cm\n#", $pdf)],
        );
    }

    #[Test]
    public function boundingBoxTilesAreDrawnPerShapeSize(): void
    {
        $pdf = $this->convert(
            '<pattern id="p" width="0.5" height="0.5"><rect width="1" height="1"/></pattern>',
            '<rect width="4" height="4" fill="url(#p)"/><rect x="5" width="4" height="4" fill="url(#p)"/>'
                . '<rect y="5" width="2" height="2" fill="url(#p)"/>',
        );

        self::assertSame(3, preg_match_all('#/PatternType 1#', $pdf));
    }

    #[Test]
    public function userSpaceTilesAreSharedBetweenShapes(): void
    {
        $pdf = $this->convert(
            '<pattern id="p" patternUnits="userSpaceOnUse" width="4" height="4"><rect width="2" height="2"/></pattern>',
            '<rect width="4" height="4" fill="url(#p)"/><rect x="5" width="2" height="2" fill="url(#p)"/>',
        );

        self::assertSame(1, preg_match_all('#/PatternType 1#', $pdf));
    }

    #[Override]
    protected function setUp(): void
    {
        $this->out = new MemoryOutput();
        $this->doc = new Document($this->out, new DocumentOptions(compress: false));
    }

    private function convert(string $defs, string $body): string
    {
        $converter = new NativeConverter();
        $svg       = SvgFile::fromData(
            "<svg xmlns=\"http://www.w3.org/2000/svg\" width=\"10\" height=\"10\"><defs>{$defs}</defs>{$body}</svg>",
        );
        $this->doc
            ->addPage(PageSize::A4)
            ->place($this->doc->svg($svg, $converter), new Box(0.0, 0.0, 100.0, 100.0), Fit::entire());
        $this->doc->close();
        self::assertSame([], $converter->warnings());

        return $this->out->contents();
    }
}
