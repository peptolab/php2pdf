<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Dimensions;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2PdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function gzcompress;
use function imagecolorat;
use function imagecreatefrompng;
use function pack;
use function str_repeat;
use function strlen;

/** A 16-bit image with a 16-bit soft mask renders blended in every reader. */
#[Group('integration')]
final class SixteenBitAlphaImageTest extends TestCase
{
    /** @return array<string, array{0: string}> */
    public static function rendererProvider(): array
    {
        return [
            'poppler'     => ['poppler'],
            'Ghostscript' => ['ghostscript'],
        ];
    }

    private static function draw(): string
    {
        $path = PdfTools::outputDir() . '/sixteen-bit-alpha.pdf';
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $doc->addPage(new Dimensions(64.0, 64.0))->place(
            $doc->imageFromData(self::halfRed()),
            new Box(0.0, 0.0, 64.0, 64.0),
            Fit::entire(),
        );
        $doc->close();
        PdfTools::assertValid($path);

        return $path;
    }

    /** An 8 by 8 PNG of red at half opacity, 16 bits per sample. */
    private static function halfRed(): string
    {
        $chunk = static fn(string $name, string $data): string => (
            pack('N', strlen($data)) . $name . $data . pack('N', 0)
        );
        $row = "\0" . str_repeat("\xFF\xFF\x00\x00\x00\x00\x80\x00", times: 8);

        return (
            "\x89PNG\r\n\x1A\n"
                . $chunk('IHDR', pack('NNCCCCC', 8, 8, 16, 6, 0, 0, 0))
                . $chunk('IDAT', (string) gzcompress(str_repeat($row, times: 8)))
                . $chunk('IEND', data: '')
        );
    }

    /**
     * CoreGraphics leaves the page transparent and manages the colour, so its render is checked
     * for the opacity alone: ext-gd's alpha runs from 0, opaque, to 127, clear.
     */
    #[Test]
    public function coreGraphicsKeepsHalfTheOpacity(): void
    {
        $png = imagecreatefrompng(PdfTools::coreGraphicsRender(self::draw()));
        self::assertNotFalse($png);

        self::assertEqualsWithDelta(63, ((int) imagecolorat($png, x: 32, y: 32) >> 24) & 0x7F, 2);
    }

    #[Test]
    #[DataProvider('rendererProvider')]
    public function halfOpaqueRedBlendsWithTheWhitePage(string $renderer): void
    {
        $path = self::draw();
        $png  = imagecreatefrompng(
            'ghostscript' === $renderer
                ? PdfTools::ghostscriptRender($path, dpi: 72)
                : PdfTools::render($path, dpi: 72),
        );
        self::assertNotFalse($png);
        $pixel = (int) imagecolorat($png, x: 32, y: 32);

        self::assertEqualsWithDelta(
            [255, 127, 127],
            [($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF],
            3,
        );
    }
}
