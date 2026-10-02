<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Font\TextOptions;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Svg\NativeConverter;
use Php2Pdf\Svg\SvgFile;
use Php2Pdf\Text\Bidi\BidiRuns;
use Php2PdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function preg_match;
use function sprintf;

/** SVG text anchors shift text by the width of the glyphs that are drawn. */
#[Group('integration')]
final class SvgTextAnchorTest extends TestCase
{
    /** @return array<string, array{0: string, 1: float}> */
    public static function anchorProvider(): array
    {
        return [
            'middle' => ['middle', 0.5],
            'end'    => ['end', 1.0],
        ];
    }

    #[Test]
    #[DataProvider('anchorProvider')]
    public function anchoredTextWithALigatureIsPlacedByItsShapedWidth(string $anchor, float $share): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(
            compress: false,
            clock: new FixedClock('2026-01-01T00:00:00Z'),
        ));
        $font = $doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $text = 'سلام';
        $svg  = SvgFile::fromData(sprintf(
            '<svg xmlns="http://www.w3.org/2000/svg" width="300" height="100">'
                . '<text x="200" y="50" font-size="20" letter-spacing="2" text-anchor="%s">%s</text></svg>',
            $anchor,
            $text,
        ));
        $doc->addPage(PageSize::A4)->place(
            $doc->svg($svg, new NativeConverter($font)),
            new Box(0.0, 0.0, 300.0, 100.0),
        );
        $doc->close();

        $options = new TextOptions(charSpacing: 2.0);
        $width   = $font->width(BidiRuns::shape($font, $text, $options), 20.0, $options);
        self::assertSame(1, preg_match('/1 0 0 -1 ([\d.]+) 50 Tm/', $out->contents(), $m));
        self::assertEqualsWithDelta(200.0 - ($share * $width), (float) $m[1], 0.001);
    }
}
