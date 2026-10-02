<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Canvas\TextlineOptions;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Fit\Position;
use Php2Pdf\Geometry\Box;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_values;
use function explode;
use function file_get_contents;
use function substr_count;
use function trim;

/**
 * A zero-width joiner is shown with the font's space glyph, so the ToUnicode map cannot read it
 * back; its line carries its text as ActualText, and the space glyph still reads as a space.
 */
#[Group('integration')]
final class ActualTextTest extends TestCase
{
    private string $path;

    #[Test]
    public function onlyTheLineThatNeedsItCarriesActualText(): void
    {
        self::assertSame(1, substr_count((string) file_get_contents($this->path), needle: '/ActualText'));
    }

    #[Test]
    public function textTheFontCannotReadBackIsCopiedAsWritten(): void
    {
        $lines = array_values(array_filter(explode("\n", trim(
            PdfTools::textInReadingOrder($this->path),
            characters: " \n\f",
        ))));

        self::assertSame(['a b c', "a\u{200D}b c"], $lines);
    }

    protected function setUp(): void
    {
        $this->path = PdfTools::outputDir() . '/actual-text.pdf';
        $document   = new Document($this->path, new DocumentOptions(
            clock: new FixedClock('2026-01-01T12:00:00Z'),
            compress: false,
        ));
        $font = $document->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $page = $document->addPage(PageSize::A4);
        $page->fitTextline(
            'a b c',
            $font,
            12.0,
            new Box(72.0, 700.0, 300.0, 20.0),
            TextlineOptions::at(Position::centerLeft()),
        );
        $page->fitTextline(
            "a\u{200D}b c",
            $font,
            12.0,
            new Box(72.0, 650.0, 300.0, 20.0),
            TextlineOptions::at(Position::centerLeft()),
        );
        $document->close();
    }
}
