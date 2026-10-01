<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Text\Flow;

use PhpPdf\Font\Glyph;
use PhpPdf\Text\Flow\Itemiser;
use PhpPdf\Text\Flow\Line;
use PhpPdf\Text\Flow\LineBreaker;
use PhpPdf\Text\Flow\Paragraph;
use PhpPdfTest\Support\KerningFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_slice;
use function mb_chr;

#[Group('unit')]
final class LineBreakerTest extends TestCase
{
    use KerningFixtureTrait;

    #[Test]
    public function aHyphenatedLineKernsItsLastLetterAgainstTheHyphen(): void
    {
        $line = $this->firstLine('OpenType', $this->serif->width('Open-', 10.0) + 0.01);
        [$n, $hyphen] = $this->lastGlyphs($line, 2);

        self::assertSame(
            [0.0, '-', $this->serif->width('Open-', 10.0)],
            [$n->kerning, mb_chr($hyphen->codePoint), $line->width],
            'n→T is −60 and n→hyphen 0.',
        );
    }

    #[Test]
    public function aLineEndingAtASpaceDropsTheKerningTowardsIt(): void
    {
        $line = $this->firstLine('AT Teams', $this->serif->width('AT Te', 10.0));

        self::assertSame(
            [0.0, $this->serif->width('AT', 10.0)],
            [$this->lastGlyphs($line, 1)[0]->kerning, $line->width],
            'T→space is −19, but the space is not drawn.',
        );
    }

    #[Test]
    public function aSpaceBeforeALineBreakDropsTheKerningTowardsIt(): void
    {
        $line = $this->firstLine("AT \nTeams", 500.0);

        self::assertSame(
            [0.0, $this->serif->width('AT', 10.0)],
            [$this->lastGlyphs($line, 1)[0]->kerning, $line->width],
        );
    }

    #[Test]
    public function wordsOnTheLineKeepTheirKerningAcrossSpaces(): void
    {
        $line = $this->firstLine('so Teams', 500.0);

        self::assertEqualsWithDelta($this->serif->width('so Teams', 10.0), $line->width, 1.0e-9);
    }

    protected function setUp(): void
    {
        $this->setUpKerning();
    }

    private function firstLine(string $text, float $width): Line
    {
        $items     = (new Itemiser($this->hyphenators))->itemise([[$text, $this->style]]);
        $paragraph = new Paragraph($this->style, $items);
        $line      = LineBreaker::nextLine($paragraph, 0, $width, 0);
        self::assertNotNull($line);

        return $line;
    }

    /** @return list<Glyph> */
    private function lastGlyphs(Line $line, int $count): array
    {
        $glyphs = [];
        foreach ($line->segments as $segment) {
            foreach ($segment->run->glyphs as $glyph) {
                $glyphs[] = $glyph;
            }
        }

        return array_slice($glyphs, -$count);
    }
}
