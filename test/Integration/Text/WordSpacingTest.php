<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Font\CodeSpace;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\TextFormat;
use Php2PdfTest\TestAsset\Font\SfntTables;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function md5_file;
use function preg_replace;
use function trim;

/**
 * Justified lines whose word spaces stretch alike are shown with word spacing (Tw), which moves
 * every glyph after the font's space. They draw as they would with each word space kerned: the
 * same paragraph is set in DejaVu Sans, and in a copy claiming more glyphs than the one-byte
 * codes reach, whose word spaces are kerned.
 */
#[Group('integration')]
final class WordSpacingTest extends TestCase
{
    private const string TEXT =
        'Justified text opens up the word spaces of every line but the last, so that each one '
            . 'reaches the right edge of its box, and nothing else about the line changes at all.';

    /** @return array<string, array{0: string}> */
    public static function rendererProvider(): array
    {
        return ['poppler' => ['poppler'], 'Ghostscript' => ['ghostscript'], 'CoreGraphics' => ['coregraphics']];
    }

    /** DejaVu Sans, whose word spaces are shown with word spacing. */
    private static function font(): string
    {
        return (string) file_get_contents(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
    }

    private static function render(string $path, string $renderer): string
    {
        $png = match ($renderer) {
            'ghostscript'  => PdfTools::ghostscriptRender($path, dpi: 72),
            'coregraphics' => PdfTools::coreGraphicsRender($path),
            default        => PdfTools::render($path, dpi: 72),
        };

        return (string) md5_file($png);
    }

    #[Test]
    public function wordSpacedTextCopiesWithOneSpaceBetweenWords(): void
    {
        $text = PdfTools::text($this->draw(self::font(), 'spaced'));

        self::assertSame(self::TEXT, trim((string) preg_replace('/\s+/', replacement: ' ', subject: $text)));
    }

    #[Test]
    #[DataProvider('rendererProvider')]
    public function wordSpacingDrawsALineAsKerningItsWordSpacesWould(string $renderer): void
    {
        $kerned = SfntTables::withGlyphCount(self::font(), CodeSpace::CODES + 1);

        self::assertSame(
            self::render($this->draw($kerned, "kerned-{$renderer}"), $renderer),
            self::render($this->draw(self::font(), "spaced-{$renderer}"), $renderer),
        );
    }

    /** The paragraph justified in a box, in the font. */
    private function draw(string $font, string $name): string
    {
        $path = PdfTools::outputDir() . "/word-spacing-{$name}.pdf";
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $flow = $doc->textflow(
            self::TEXT,
            new TextStyle(
                font: $doc->fonts()->embed(OpenTypeFont::fromData($font)),
                size: 10.0,
                alignment: Alignment::Justify,
            ),
            TextFormat::Plain,
        );
        $doc->addPage(new Dimensions(240.0, 120.0))->fitTextflow($flow, new Box(20.0, 10.0, 200.0, 100.0));
        $doc->close();
        PdfTools::assertValid($path);

        return $path;
    }
}
