<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font;

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Font\CodeSpace;
use Php2Pdf\Font\Font;
use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2Pdf\Font\TextOptions;
use Php2Pdf\Geometry\Dimensions;
use Php2Pdf\Geometry\Matrix;
use Php2PdfTest\TestAsset\Font\SfntTables;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function file_put_contents;
use function md5_file;
use function min;
use function pack;
use function preg_match;
use function substr_replace;

/**
 * A glyph written with a two-byte code, after thousands of others, draws as it does with the
 * one-byte code it gets when it is the first: the encoding CMap maps every code to its glyph. A
 * font with more glyphs than the codes reach keeps two-byte codes.
 */
#[Group('integration')]
final class CompactCodesTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> font, renderer */
    public static function renderProvider(): array
    {
        $cases = [];
        foreach ([
            'TrueType' => 'DejaVuSans.ttf',
            'CFF'      => 'SourceSans3-Regular.otf',
        ] as $name => $font) {
            foreach (['poppler', 'ghostscript', 'coregraphics'] as $renderer) {
                $cases["{$name}, {$renderer}"] = [$font, $renderer];
            }
        }

        return $cases;
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
    public function aFontWithMoreGlyphsThanTheCodesReachKeepsTwoByteCodes(): void
    {
        $data = (string) file_get_contents(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $maxp = (string) OpenTypeFont::fromData($data)->table('maxp');
        $path = PdfTools::outputDir() . '/compact-codes-big.ttf';
        file_put_contents($path, SfntTables::replace($data, [
            'maxp' => substr_replace($maxp, pack('n', CodeSpace::CODES + 1), offset: 4, length: 2),
        ]));
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(compress: false));
        $doc->addPage()->canvas()->drawText('AB', $doc->fonts()->load($path), 12.0, 10.0, 10.0);
        $doc->close();

        self::assertSame([1, 1, 1], [
            preg_match('#/Encoding/Identity-H#', $out->contents()),
            preg_match("#\\(\x00\x01\x00\x02\\) Tj#", $out->contents()),
            preg_match("#<0001> <0041>\n<0002> <0042>#", $out->contents()),
        ]);
    }

    #[Test]
    #[DataProvider('renderProvider')]
    public function aGlyphDrawsTheSameWhateverTheLengthOfItsCode(string $font, string $renderer): void
    {
        $alone = $this->draw($font, fillers: 0, name: "alone-{$renderer}");
        $after = $this->draw($font, fillers: 4500, name: "after-{$renderer}");

        self::assertSame(self::render($alone, $renderer), self::render($after, $renderer));
    }

    #[Test]
    public function textWrittenWithLongCodesCopiesBack(): void
    {
        $path = $this->draw('DejaVuSans.ttf', fillers: 4500, name: 'copy');

        self::assertStringContainsString('Ж', PdfTools::text($path));
    }

    /**
     * A page showing Ж, after $fillers other glyphs of the font drawn off the page, so its code is
     * the one after theirs and nothing else is seen.
     */
    private function draw(string $font, int $fillers, string $name): string
    {
        $path = PdfTools::outputDir() . "/compact-codes-{$name}-{$font}.pdf";
        $doc  = new Document($path, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));
        $face = $doc->fonts()->load(PdfTools::assetPath("fonts/{$font}"));
        self::assertInstanceOf(Font::class, $face);
        $canvas = $doc->addPage(new Dimensions(100.0, 100.0))->canvas();
        $target = $face->program()->glyphId(0x0416);
        $glyphs = [];
        for ($id = 1, $last = min($fillers, $face->program()->numGlyphs - 1); $id <= $last; ++$id) {
            if ($id === $target) {
                continue;
            }
            // Characters from the private use area, so the fillers copy as something.
            $glyphs[] = new Glyph($id, 0xE000 + $id, 0);
        }
        if ([] !== $glyphs) {
            $canvas->showRun(
                new GlyphRun($glyphs),
                $face,
                10.0,
                Matrix::translation(-10_000.0, -10_000.0),
                TextOptions::default(),
            );
        }
        $canvas->showRun(
            new GlyphRun([new Glyph($target, 0x0416, $face->advance($target))]),
            $face,
            60.0,
            Matrix::translation(20.0, 30.0),
            TextOptions::default(),
        );
        $doc->close();
        PdfTools::assertValid($path);

        return $path;
    }
}
