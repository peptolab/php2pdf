<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text;

use Override;
use Php2Pdf\Canvas\TextlineOptions;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Num;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Fit\FitMethod;
use Php2Pdf\Fit\Position;
use Php2Pdf\Font\Font;
use Php2Pdf\Geometry\Box;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function sprintf;
use function substr_count;

#[Group('integration')]
final class TextlineTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;
    private Font $font;

    private static function num(float $v, int $decimals = 4): string
    {
        return Num::format($v, $decimals);
    }

    #[Test]
    public function drawsUnderlineAndStrikeoutFromMetrics(): void
    {
        $this->doc->addPage()->fitTextline(
            'Under',
            $this->font,
            10.0,
            new Box(10.0, 10.0, 100.0, 20.0),
            new TextlineOptions(
                underline: true,
                strikeout: true,
            ),
        );
        $this->doc->close();
        $pdf = $this->out->contents();

        $width      = $this->font->width('Under', 10.0);
        $descent    = -$this->font->descender(10.0);
        $underlineY = $descent + $this->font->underlinePosition(10.0) - ($this->font->underlineThickness(10.0) / 2.0);
        self::assertStringContainsString(
            sprintf(
                'cm 0 %s %s %s re f Q',
                self::num($underlineY),
                self::num($width),
                self::num($this->font->underlineThickness(10.0)),
            ),
            $pdf,
        );
        self::assertSame(2, substr_count($pdf, needle: ' re f Q'));
    }

    #[Test]
    public function positionsTheLineInTheBox(): void
    {
        $box       = new Box(100.0, 100.0, 200.0, 50.0);
        $placement = $this->doc->addPage()->fitTextline(
            'Hello',
            $this->font,
            10.0,
            $box,
            TextlineOptions::at(Position::topRight()),
        );
        $this->doc->close();

        $width = $this->font->width('Hello', 10.0);
        self::assertEqualsWithDelta($width, $placement->width, 1e-9);
        self::assertSame(10.0, $placement->fontSize);
        self::assertTrue($placement->fits);
        // Right-aligned: the line ends at the box's right edge; top-aligned: the ascender touches the top.
        self::assertEqualsWithDelta(300.0 - $width, $placement->baselineX, 1e-6);
        self::assertEqualsWithDelta(150.0 - $this->font->ascender(10.0), $placement->baselineY, 1e-6);
        self::assertStringContainsString(
            sprintf(' %s %s Td ', self::num($placement->baselineX, 6), self::num($placement->baselineY, 6)),
            $this->out->contents(),
        );
    }

    #[Test]
    public function rotatesAndScalesLikeOtherObjects(): void
    {
        $page      = $this->doc->addPage();
        $box       = new Box(50.0, 50.0, 20.0, 200.0);
        $placement = $page->fitTextline(
            'Vertical',
            $this->font,
            12.0,
            $box,
            new TextlineOptions(Position::center(), FitMethod::Meet, 90.0),
        );
        $this->doc->close();

        self::assertEqualsWithDelta(50.0 + 10.0 - ($placement->bounds->width / 2.0), $placement->bounds->x, 1e-6);
        self::assertTrue($placement->fits);
        self::assertMatchesRegularExpression('/ Tf 0 [0-9.]+ -[0-9.]+ 0 [0-9.]+ [0-9.]+ Tm /', $this->out->contents());
    }

    #[Test]
    public function shrinksToFitDownToTheLimit(): void
    {
        $page  = $this->doc->addPage();
        $width = $this->font->width('A rather long line of text', 20.0);

        $fits = $page->fitTextline(
            'A rather long line of text',
            $this->font,
            20.0,
            new Box(0.0, 0.0, $width * 0.9, 100.0),
            TextlineOptions::shrinkToFit(),
        );
        self::assertEqualsWithDelta(18.0, $fits->fontSize, 1e-6);
        self::assertTrue($fits->fits);

        $limited = $page->fitTextline(
            'A rather long line of text',
            $this->font,
            20.0,
            new Box(0.0, 0.0, $width * 0.5, 100.0),
            TextlineOptions::shrinkToFit(shrinkLimit: 0.75),
        );
        self::assertEqualsWithDelta(15.0, $limited->fontSize, 1e-6);
        self::assertFalse($limited->fits);

        $this->doc->close();
        self::assertMatchesRegularExpression('/ 0\.9 0 0 0\.9 [0-9.]+ [0-9.]+ Tm /', $this->out->contents());
    }

    #[Override]
    protected function setUp(): void
    {
        $this->out = new MemoryOutput();
        $this->doc = new Document($this->out, new DocumentOptions(
            clock: new FixedClock('2026-01-01'),
            compress: false,
            xmp: false,
        ));
        $this->font = $this->doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
    }
}
