<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text;

use Override;
use Php2Pdf\Core\Num;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Text\Style\TabStop;
use Php2Pdf\Text\Style\TextStyle;
use Php2PdfTest\TestAsset\PdfTools;
use Php2PdfTest\TestAsset\TextShows;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function ceil;
use function floor;
use function str_starts_with;
use function strlen;
use function substr;

/**
 * Tab leaders (#37). In DejaVu Sans at 10 pt, "a" is 6.13 wide, "bb" 12.7, "abc" 18.6 and the
 * period 3.18; a glyph is two bytes in the content stream.
 */
#[Group('integration')]
final class TabLeaderTest extends TestCase
{
    private MemoryOutput $out;
    private Document $doc;

    /** @return array<string, array{0: string, 1: list<array{0: string, 1: int}>}> */
    public static function leaderProvider(): array
    {
        return [
            'from the next grid point to the stop' => ["a\tbb", [['6.36', 25]]],
            'lines share the grid'                 => ["a\tbb\nabc\tbb", [['6.36', 25], ['19.08', 21]]],
        ];
    }

    #[Test]
    public function aLeaderOfSeveralCharactersRepeatsAsAUnit(): void
    {
        $font    = $this->doc->fonts()->find('DejaVuSans');
        $unit    = $font->width('. ', 10.0);
        $texts   = $this->texts("a\tbb", TabStop::right(100.0, '. '));
        $leader  = $texts[1] ?? ['', ''];
        $repeats = (int) floor((87.3 - (ceil(6.13 / $unit) * $unit)) / $unit);

        // The font's first glyphs are written with one byte each.
        self::assertSame(2 * $repeats, strlen($leader[1]));
    }

    /** @param list<array{0: string, 1: int}> $expected */
    #[Test]
    #[DataProvider('leaderProvider')]
    public function aLeaderRepeatsAcrossTheTabOnAGrid(string $text, array $expected): void
    {
        $leaders = [];
        foreach ($this->texts($text, TabStop::right(100.0, '.')) as [$x, $bytes]) {
            if ('0' === $x || '87.3' === $x) {
                continue;
            }
            $leaders[] = [$x, strlen($bytes)];
        }

        self::assertSame($expected, $leaders);
    }

    #[Test]
    public function anEmptyLeaderIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('at least one character');
        TabStop::right(100.0, '');
    }

    #[Test]
    public function noLeaderIsDrawnWhereNoneFits(): void
    {
        self::assertCount(2, $this->texts("a\tb", TabStop::left(8.0, '.')));
    }

    #[Override]
    protected function setUp(): void
    {
        $this->out = new MemoryOutput();
        $this->doc = new Document($this->out, new DocumentOptions(compress: false));
        $this->doc->fonts()->addSearchPath(PdfTools::assetPath('fonts'));
    }

    /**
     * The x position and glyph bytes of each run shown as one string.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function texts(string $text, TabStop $tab): array
    {
        $style = new TextStyle(
            font: 'DejaVuSans',
            size: 10.0,
            tabs: [$tab],
        );
        $this->doc->addPage()->fitTextflow($this->doc->textflow($text, $style), new Box(0.0, 0.0, 300.0, 100.0));
        $this->doc->close();
        $out = [];
        foreach (TextShows::of($this->out->contents()) as [$x, , $shown]) {
            if (! str_starts_with($shown, '(')) {
                continue;
            }

            $out[] = [Num::format($x, 6), substr($shown, offset: 1, length: -1)];
        }

        return $out;
    }
}
