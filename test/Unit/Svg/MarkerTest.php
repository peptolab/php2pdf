<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Svg;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Svg\NativeConverter;
use Php2Pdf\Svg\SvgFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function preg_match_all;

/** SVG markers drawn at path vertices (#37). The marker below is a 4 by 4 green square. */
#[Group('unit')]
final class MarkerTest extends TestCase
{
    private const string SQUARE = '<rect width="4" height="4" fill="lime"/>';

    private MemoryOutput $out;
    private Document $doc;

    /** @return array<string, array{0: string, 1: string}> */
    public static function orientProvider(): array
    {
        return [
            'fixed angle in turns' => ['0.25turn', "\n0 1 -1 0 10 5 cm\n"],
            'auto'                 => ['auto', "\n1 0 0 1 10 5 cm\n"],
            'auto-start-reverse'   => ['auto-start-reverse', "\n-1 0 0 -1 0 5 cm\n"],
        ];
    }

    #[Test]
    public function aMarkerInsideItselfIsRejectedAsCircular(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('The SVG refers to "m" from within itself: marker #m -> marker #m.');
        $this->convert(
            '<marker id="m"><path d="M0 0 L4 4" stroke="lime" marker-end="url(#m)"/></marker>',
            '<path d="M0 0 L10 0" stroke="black" marker-end="url(#m)"/>',
        );
    }

    #[Test]
    public function aMarkerIsPlacedWithItsReferencePointOnTheVertex(): void
    {
        $pdf = $this->convert(
            '<marker id="m" markerUnits="userSpaceOnUse" markerWidth="4" markerHeight="4" refX="2" refY="1">'
                . self::SQUARE
                . '</marker>',
            '<line x1="0" y1="5" x2="10" y2="5" stroke="black" marker-start="url(#m)" marker-end="url(#m)"/>',
        );

        self::assertSame(
            [1, 1, 2],
            [
                preg_match("#\n1 0 0 1 -2 4 cm\n0 0 m\n4 0 l\n4 4 l\n0 4 l\nh\nW n\n#", $pdf),
                preg_match("#\n1 0 0 1 8 4 cm\n#", $pdf),
                preg_match_all('#0 1 0 rg#', $pdf),
            ],
        );
    }

    #[Test]
    public function markersAreInheritedFromTheGroup(): void
    {
        $pdf = $this->convert(
            '<marker id="m">' . self::SQUARE . '</marker>',
            '<g marker-end="url(#m)"><path d="M0 0 L10 0" stroke="black"/></g>',
        );

        self::assertSame(1, preg_match_all('#0 1 0 rg#', $pdf));
    }

    #[Test]
    public function markersScaleWithTheStrokeWidthByDefault(): void
    {
        $pdf = $this->convert(
            '<marker id="m" viewBox="0 0 8 8" markerWidth="2" markerHeight="2">' . self::SQUARE . '</marker>',
            '<path d="M1 5 L10 5" stroke="black" stroke-width="3" marker-start="url(#m)"/>',
        );

        self::assertSame(
            [1, 1],
            [
                preg_match("#\n3 0 0 3 1 5 cm\n0 0 m\n2 0 l\n2 2 l\n0 2 l\nh\nW n\n#", $pdf),
                preg_match("#\n0\.25 0 0 0\.25 0 0 cm\n#", $pdf),
            ],
        );
    }

    #[Test]
    public function midMarkersSkipTheEnds(): void
    {
        $pdf = $this->convert(
            '<marker id="m">' . self::SQUARE . '</marker>',
            '<polyline points="0,0 5,5 10,0 10,10" fill="none" stroke="black" marker-mid="url(#m)"/>',
        );

        self::assertSame(2, preg_match_all('#0 1 0 rg#', $pdf));
    }

    #[Test]
    #[DataProvider('orientProvider')]
    public function orientTurnsTheMarker(string $orient, string $matrix): void
    {
        $pdf = $this->convert(
            "<marker id=\"m\" markerUnits=\"userSpaceOnUse\" orient=\"{$orient}\">" . self::SQUARE . '</marker>',
            '<line x1="0" y1="5" x2="10" y2="5" stroke="black" marker-start="url(#m)" marker-end="url(#m)"/>',
        );

        self::assertStringContainsString($matrix, $pdf);
    }

    #[Test]
    public function overflowVisibleMarkersAreNotClipped(): void
    {
        $pdf = $this->convert(
            '<marker id="m" overflow="visible">' . self::SQUARE . '</marker>',
            '<path d="M0 0 L10 0" stroke="black" marker-end="url(#m)"/>',
        );

        self::assertSame([1, 0], [preg_match_all('#0 1 0 rg#', $pdf), preg_match('#h\nW n#', $pdf)]);
    }

    #[Test]
    public function shapesOtherThanPathsTakeNoMarkers(): void
    {
        $pdf = $this->convert(
            '<marker id="m">'
                . self::SQUARE
                . '</marker><marker id="empty" markerWidth="0">'
                . self::SQUARE
                . '</marker>',
            '<rect width="5" height="5" marker-start="url(#m)"/><path d="M0 0 L10 0" stroke="black" marker-end="url(#empty)"/>'
                . '<path d="M0 0 L10 0" stroke="black" marker-end="url(#missing)"/>',
        );

        self::assertSame(0, preg_match_all('#0 1 0 rg#', $pdf));
    }

    #[Test]
    public function theMarkerShorthandSetsEveryVertex(): void
    {
        $pdf = $this->convert(
            '<marker id="m">' . self::SQUARE . '</marker>',
            '<polyline points="0,0 5,5 10,0" fill="none" stroke="black" style="marker: url(#m)"/>',
        );

        self::assertSame(3, preg_match_all('#0 1 0 rg#', $pdf));
    }

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
