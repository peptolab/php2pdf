<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text\Flow;

use Override;
use Php2Pdf\Font\Glyph;
use Php2Pdf\Text\Flow\BreakPlanner;
use Php2Pdf\Text\Flow\Itemiser;
use Php2Pdf\Text\Flow\Line;
use Php2Pdf\Text\Flow\LineAssembler;
use Php2Pdf\Text\Flow\LineBreaker;
use Php2Pdf\Text\Flow\Paragraph;
use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\TextStyle;
use Php2PdfTest\Trait\KerningFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;
use function implode;
use function mb_chr;

#[Group('integration')]
final class LineBreakerTest extends TestCase
{
    use KerningFixtureTrait;

    private static function text(Line $line): string
    {
        $text = '';
        foreach ($line->segments as $segment) {
            foreach ($segment->run->glyphs as $glyph) {
                $text .= $glyph->text();
            }
        }

        return $text;
    }

    #[Test]
    public function aHyphenatedLineKernsItsLastLetterAgainstTheHyphen(): void
    {
        $line   = $this->firstLine('OpenType', $this->serif->width('Open-', 10.0) + 0.01);
        $n      = $this->lastGlyph($line, 2);
        $hyphen = $this->lastGlyph($line);

        self::assertSame(
            [0.0, '-', $this->serif->width('Open-', 10.0)],
            [$n->kerning, mb_chr($hyphen->codePoint), $line->width],
            'n→T is −60 and n→hyphen 0.',
        );
    }

    #[Test]
    public function aLineEndingAtAFixedSpaceDropsIt(): void
    {
        $line = $this->firstLine("Total\u{2003}due", $this->serif->width("Total\u{2003}d", 10.0));

        self::assertSame(
            ['l', $this->serif->width('Total', 10.0)],
            [mb_chr($this->lastGlyph($line)->codePoint), $line->width],
        );
    }

    #[Test]
    public function aLineEndingAtAHardHyphenAddsNoHyphen(): void
    {
        $line = $this->firstLine('well-known', $this->serif->width('well-kn', 10.0));

        self::assertSame(
            ['-', $this->serif->width('well-', 10.0)],
            [mb_chr($this->lastGlyph($line)->codePoint), $line->width],
        );
    }

    #[Test]
    public function aLineEndingAtASpaceDropsTheKerningTowardsIt(): void
    {
        $line = $this->firstLine('AT Teams', $this->serif->width('AT Te', 10.0));

        self::assertSame(
            [0.0, $this->serif->width('AT', 10.0)],
            [$this->lastGlyph($line)->kerning, $line->width],
            'T→space is −19, but the space is not drawn.',
        );
    }

    #[Test]
    public function aSpaceBeforeALineBreakDropsTheKerningTowardsIt(): void
    {
        $line = $this->firstLine("AT \nTeams", 500.0);

        self::assertSame(
            [0.0, $this->serif->width('AT', 10.0)],
            [$this->lastGlyph($line)->kerning, $line->width],
        );
    }

    #[Test]
    public function aWordTooWideForAnyLineRunsOnAcrossLinesAndLeavesTheParagraphAsItWas(): void
    {
        $word      = 'Donaudampfschifffahrtsgesellschaft';
        $items     = (new Itemiser($this->hyphenators))->itemise([[$word, $this->style]]);
        $paragraph = new Paragraph($this->style, $items);
        $width     = $this->serif->width('Donaudampf', 10.0);
        $lines     = [];
        $offset    = 0;
        $carry     = 0;
        while (
            null !== ($line = (new LineBreaker(new BreakPlanner()))->nextLine(
                $paragraph,
                $offset,
                $width,
                count($lines),
                $carry,
            ))
        ) {
            $lines[] = self::text($line);
            $offset  += $line->itemsConsumed;
            $carry   = $line->carry;
            if ($line->last) {
                break;
            }
        }

        self::assertSame(
            [$word, true, true],
            [implode('', $lines), count($lines) > 2, $items === $paragraph->items],
        );
    }

    #[Test]
    public function justificationStretchesEachSpaceByItsOwnWidth(): void
    {
        $large = $this->style->merge(new TextStyle(size: 20.0));
        $items = (new Itemiser())->itemise([['aa bb ', $this->style], ['cc dd', $large]]);
        $line  = LineAssembler::line($items, null, $this->style, [Alignment::Justify, 0], 0.0, 300.0, [0, 0], false);
        $added = [];
        foreach ($line->segments as $segment) {
            foreach ($segment->run->glyphs as $glyph) {
                if (0x20 !== $glyph->codePoint) {
                    continue;
                }
                $added[] = $glyph->kerning;
            }
        }

        self::assertSame(3, count($added));
        self::assertEqualsWithDelta(
            [$added[0] ?? 0.0, $added[0] ?? 0.0],
            [$added[1] ?? 0.0, $added[2] ?? 0.0],
            1.0e-9,
            'Each space gains the same share of its own width, in thousandths of an em, whatever its size.',
        );
    }

    #[Test]
    public function wordsOnTheLineKeepTheirKerningAcrossSpaces(): void
    {
        $line = $this->firstLine('so Teams', 500.0);

        self::assertEqualsWithDelta($this->serif->width('so Teams', 10.0), $line->width, 1.0e-9);
    }

    #[Override]
    protected function setUp(): void
    {
        [$this->serif, $this->style, $this->hyphenators] = self::kerning();
    }

    private function firstLine(string $text, float $width): Line
    {
        $items     = (new Itemiser($this->hyphenators))->itemise([[$text, $this->style]]);
        $paragraph = new Paragraph($this->style, $items);
        $line      = (new LineBreaker(new BreakPlanner()))->nextLine($paragraph, 0, $width, 0);
        self::assertNotNull($line);

        return $line;
    }

    /** The glyph $fromEnd places from the end of the line, failing the test if there is none. */
    private function lastGlyph(Line $line, int $fromEnd = 1): Glyph
    {
        $glyphs = [];
        foreach ($line->segments as $segment) {
            foreach ($segment->run->glyphs as $glyph) {
                $glyphs[] = $glyph;
            }
        }

        $glyph = $glyphs[count($glyphs) - $fromEnd] ?? null;
        self::assertNotNull($glyph);

        return $glyph;
    }
}
