<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2PdfTest\TestAsset\Image\Jbig2Segments;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_unique;
use function count;
use function file_put_contents;
use function imagecolorat;
use function imagecreatefrompng;
use function imagesx;
use function preg_match_all;

/** JBIG2 files pass through as JBIG2Decode and render as their pages (#47). */
#[Group('integration')]
final class Jbig2ImageTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function rendererProvider(): array
    {
        return [
            'poppler'      => ['poppler'],
            'Ghostscript'  => ['ghostscript'],
            'CoreGraphics' => ['coregraphics'],
        ];
    }

    /** The page is a black bar down columns 1 to 4 of 8, from top to bottom. */
    #[Test]
    #[DataProvider('rendererProvider')]
    public function aJbig2PageRendersAsItsPicture(string $renderer): void
    {
        $file = PdfTools::outputDir() . '/jbig2-bar.jb2';
        file_put_contents($file, Jbig2Segments::file(Jbig2Segments::barPage()));
        $path = PdfTools::outputDir() . '/jbig2-bar.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->addPage(new Dimensions(64.0, 64.0))->place(
            $doc->image($file),
            new Box(0.0, 0.0, 64.0, 64.0),
            Fit::entire(),
        );
        $doc->close();
        PdfTools::assertValid($path);
        $png = imagecreatefrompng(match ($renderer) {
            'ghostscript'  => PdfTools::ghostscriptRender($path, dpi: 72),
            'coregraphics' => PdfTools::coreGraphicsRender($path),
            default        => PdfTools::render($path, dpi: 72),
        });
        self::assertNotFalse($png);
        $pixel = imagesx($png) / 8;
        $grey  = static fn(int $x, int $y): int => (
            (int) imagecolorat($png, (int) (($x + 0.5) * $pixel), (int) (($y + 0.5) * $pixel)) & 0xFF
        );

        self::assertSame([255, 0, 0, 255, 0, 255], [
            $grey(
                x: 0,
                y: 0,
            ),
            $grey(
                x: 1,
                y: 0,
            ),
            $grey(
                x: 4,
                y: 0,
            ),
            $grey(
                x: 5,
                y: 0,
            ),
            $grey(
                x: 2,
                y: 7,
            ),
            $grey(
                x: 7,
                y: 7,
            ),
        ]);
    }

    #[Test]
    public function imagesSharingGlobalsShareOneGlobalsStream(): void
    {
        $out        = new MemoryOutput();
        $doc        = new Document($out, new DocumentOptions(compress: false));
        $dictionary = Jbig2Segments::segment(4, Jbig2Segments::SYMBOL_DICTIONARY, 0, 'symbols');
        $page       = $doc->addPage();
        // The second file numbers its segments differently, so the two pages' data differ.
        foreach ([0, 10] as $first) {
            $page->place(
                $doc->imageFromData(Jbig2Segments::file([
                    $dictionary,
                    Jbig2Segments::segment(
                        $first,
                        Jbig2Segments::PAGE_INFORMATION,
                        1,
                        Jbig2Segments::pageInformation(8, 8),
                    ),
                    Jbig2Segments::segment(
                        $first + 1,
                        Jbig2Segments::IMMEDIATE_GENERIC_REGION,
                        1,
                        Jbig2Segments::bar(),
                    ),
                ])),
                new Box(0.0, 0.0, 64.0, 64.0),
            );
        }
        $doc->close();
        $globals = [];
        $found   = preg_match_all('#/JBIG2Globals (\d+) 0 R#', $out->contents(), $globals);

        self::assertSame([2, 1], [$found, count(array_unique($globals[1] ?? []))]);
    }
}
