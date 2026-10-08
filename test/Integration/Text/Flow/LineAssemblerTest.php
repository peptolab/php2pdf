<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text\Flow;

use Override;
use Php2Pdf\Canvas\PlaceableInterface;
use Php2Pdf\Font\Glyph;
use Php2Pdf\Text\Flow\InlineObject;
use Php2Pdf\Text\Flow\Item;
use Php2Pdf\Text\Flow\Itemiser;
use Php2Pdf\Text\Flow\Line;
use Php2Pdf\Text\Flow\LineAssembler;
use Php2Pdf\Text\Flow\LineEnd;
use Php2Pdf\Text\Flow\LineWidths;
use Php2Pdf\Text\Flow\Paragraph;
use Php2Pdf\Text\Flow\ParagraphComposer;
use Php2Pdf\Text\Flow\Segment;
use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\Justification;
use Php2Pdf\Text\Style\TextStyle;
use Php2PdfTest\Trait\KerningFixtureTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function array_map;
use function implode;

/**
 * Justified lines of Source Serif 4 at 10 pt, whose word spaces may stretch to 133% and shrink to
 * 80% unless a test says otherwise. Letter spacing is a percentage of a word space; kerning is in
 * thousandths of an em, so a point at 10 pt is 100.
 */
#[Group('integration')]
final class LineAssemblerTest extends TestCase
{
    use KerningFixtureTrait;

    private const string TEXT = 'aa bb cc';

    /** @return array<string, array{Justification, list<int>}> */
    public static function tightLineProvider(): array
    {
        return [
            'word spaces alone'                => [new Justification(), [3, 7]],
            'letters closing up by 5% a space' => [new Justification(minLetterSpacing: -5.0), [5, 7]],
        ];
    }

    /**
     * The kerning each glyph of a line gained over its natural setting.
     *
     * @return list<float>
     */
    private static function added(Line $line, Line $natural): array
    {
        $natural = self::glyphs($natural);

        return array_map(
            static fn(Glyph $glyph, int $i): float => $glyph->kerning - ($natural[$i] ?? $glyph)->kerning,
            self::glyphs($line),
            array_keys(self::glyphs($line)),
        );
    }

    /** @return list<Glyph> */
    private static function glyphs(Line $line): array
    {
        $glyphs = [];
        foreach ($line->segments as $segment) {
            foreach ($segment->run->glyphs as $glyph) {
                $glyphs[] = $glyph;
            }
        }

        return $glyphs;
    }

    /** @return list<float> */
    private static function scales(Line $line): array
    {
        return array_map(static fn(Segment $segment): float => $segment->scale, $line->segments);
    }

    #[Test]
    public function aBreakTheLineDoesNotTakeLeavesItsTextInOneSegment(): void
    {
        $items = (new Itemiser($this->hyphenators))->itemise([['aa open-type bb', $this->style]]);
        $line  = LineAssembler::line($items, null, $this->style, [Alignment::Left, 0], 0.0, 300.0, [0, 0], false);

        self::assertSame(['aa open-type bb'], array_map(
            static fn(Segment $segment): string => implode(array_map(
                static fn(Glyph $glyph): string => $glyph->text(),
                $segment->run->glyphs,
            )),
            $line->segments,
        ));
    }

    /** @param list<int> $expected */
    #[Test]
    #[DataProvider('tightLineProvider')]
    public function aLineFitsOnlyIfItsLettersMayCloseUp(Justification $justification, array $expected): void
    {
        $tight = $this->width() - (2 * 0.2 * $this->space()) - (0.5 * 7 * 0.05 * $this->space());
        $style = $this->style->merge(new TextStyle(
            alignment: Alignment::Justify,
            justification: $justification,
        ));
        $paragraph = new Paragraph($style, (new Itemiser())->itemise([[self::TEXT . ' dd', $style]]));
        $ends      = ParagraphComposer::breaks($paragraph, 0, new LineWidths($tight, $style), 0);

        self::assertSame(
            $expected,
            null === $ends ? null : array_map(static fn(LineEnd $end): int => $end->index, $ends),
        );
    }

    #[Test]
    public function aLineWithoutWordSpacesTakesTheRestBetweenItsLetters(): void
    {
        $justification = new Justification(maxLetterSpacing: 10.0);
        $natural       = $this->serif->width('aaaa', 10.0);
        $line          = $this->justify('aaaa', $natural + (3 * 0.2 * $this->space()), $justification);

        self::assertEqualsWithDelta(
            [20.0 * $this->space(), 20.0 * $this->space(), 20.0 * $this->space(), 0.0],
            self::added($line, $this->natural('aaaa', $justification)),
            1.0e-9,
            'Twice the letters\' limit, as there are no word spaces to take it.',
        );
    }

    #[Test]
    public function anInlineObjectNeitherSpacesNorScales(): void
    {
        $justification = new Justification(
            maxLetterSpacing: 10.0,
            maxGlyphScaling: 101.0,
        );
        $style = $this->style->merge(new TextStyle(justification: $justification));
        $items = [
            ...(new Itemiser())->itemise([['aa bb', $style]]),
            Item::inline(new InlineObject($this->createStub(PlaceableInterface::class), 5.0, 5.0), $style),
        ];
        $text      = $this->serif->width('aa bb', 10.0) + (0.33 * $this->space()) + (4 * 0.1 * $this->space());
        $available = ($text * 1.01) + 5.0;
        $line      = LineAssembler::line($items, null, $style, [Alignment::Justify, 0], 0.0, $available, [0, 0], false);

        self::assertEqualsWithDelta(
            [$text * 1.01, $available],
            [$line->inlines[0][0] ?? 0.0, $line->width],
            1.0e-9,
            'The text is set at its widest; the object keeps its 5 pt.',
        );
    }

    #[Test]
    public function aWordSpaceThatMayNotStretchLeavesTheSlackToTheOthers(): void
    {
        $rigid = $this->style->merge(new TextStyle(justification: new Justification(maxWordSpacing: 100.0)));
        $items = (new Itemiser())->itemise([['aa ', $rigid], ['bb cc', $this->style]]);
        $slack = 0.5 * $this->wordStretch();
        $line  = LineAssembler::line(
            $items,
            null,
            $this->style,
            [Alignment::Justify, 0],
            0.0,
            $this->width() + $slack,
            [0, 0],
            false,
        );
        $natural = LineAssembler::line($items, null, $this->style, [Alignment::Left, 0], 0.0, 300.0, [0, 0], false);
        $added   = self::added($line, $natural);

        self::assertEqualsWithDelta([0.0, $slack * 100.0], [$added[2] ?? null, $added[5] ?? null], 1.0e-6);
    }

    #[Test]
    public function byDefaultOnlyWordSpacesChange(): void
    {
        $line = $this->justify(self::TEXT, $this->width() + 3.0, new Justification());

        self::assertSame(
            [[1.0], [0.0, 0.0, 0.0, 0.0, 0.0, 0.0]],
            [self::scales($line), $this->letterKerning($line, new Justification())],
        );
    }

    #[Test]
    public function glyphsScaleForWhatLetterSpacingCannotTake(): void
    {
        $justification = new Justification(
            maxLetterSpacing: 10.0,
            maxGlyphScaling: 105.0,
        );
        $set  = $this->width() + $this->wordStretch() + $this->letterStretch();
        $line = $this->justify(self::TEXT, $set * 1.025, $justification);

        self::assertEqualsWithDelta(
            [[1.025], $set * 1.025],
            [self::scales($line), $line->width],
            1.0e-9,
        );
    }

    #[Test]
    public function lettersOpenUpForWhatWordSpacesCannotTake(): void
    {
        $justification = new Justification(maxLetterSpacing: 10.0);
        $available     = $this->width() + $this->wordStretch() + (0.5 * $this->letterStretch());
        $line          = $this->justify(self::TEXT, $available, $justification);
        $added         = self::added($line, $this->natural(self::TEXT, $justification));
        $half          = 0.5 * 0.1 * $this->space() * 100.0;
        $space         = $half + (0.33 * $this->space() * 100.0);

        self::assertEqualsWithDelta(
            [$half, $half, $space, $half, $half, $space, $half, 0.0, $available],
            [...$added, $line->width],
            1.0e-9,
            'Half the letters\' limit after each glyph but the last; the spaces at their maximum.',
        );
    }

    #[Test]
    public function scalingStopsAtItsLimitAndWordSpacesTakeTheRest(): void
    {
        $justification = new Justification(
            maxLetterSpacing: 10.0,
            maxGlyphScaling: 102.0,
        );
        $set       = $this->width() + $this->wordStretch() + $this->letterStretch();
        $available = ($set * 1.02) + 4.0;
        $line      = $this->justify(self::TEXT, $available, $justification);
        $added     = self::added($line, $this->natural(self::TEXT, $justification));
        $past      = ((2.0 / 1.02) + (0.33 * $this->space()) + (0.1 * $this->space())) * 100.0;

        self::assertEqualsWithDelta(
            [[1.02], $past, $past, $available],
            [self::scales($line), $added[2] ?? 0.0, $added[5] ?? 0.0, $line->width],
            1.0e-9,
            'Each space takes half of the 4 pt left over, before scaling.',
        );
    }

    #[Test]
    public function tightLinesShrinkInTheSameOrder(): void
    {
        $justification = new Justification(
            minLetterSpacing: -5.0,
            minGlyphScaling: 95.0,
        );
        $set  = $this->width() - (2 * 0.2 * $this->space()) - (7 * 0.05 * $this->space());
        $line = $this->justify(self::TEXT, $set * 0.98, $justification);

        self::assertEqualsWithDelta(
            [[0.98], $set * 0.98],
            [self::scales($line), $line->width],
            1.0e-9,
        );
    }

    #[Test]
    public function wordSpacesTakeTheSlackFirst(): void
    {
        $justification = new Justification(
            maxLetterSpacing: 10.0,
            maxGlyphScaling: 110.0,
        );
        $available = $this->width() + (0.5 * $this->wordStretch());
        $line      = $this->justify(self::TEXT, $available, $justification);

        self::assertEqualsWithDelta(
            [[1.0], [0.0, 0.0, 0.0, 0.0, 0.0, 0.0], $available],
            [self::scales($line), $this->letterKerning($line, $justification), $line->width],
            1.0e-9,
        );
    }

    #[Override]
    protected function setUp(): void
    {
        [$this->serif, $this->style, $this->hyphenators] = self::kerning();
    }

    private function justify(string $text, float $available, Justification $justification): Line
    {
        return $this->line($text, $available, Alignment::Justify, $justification);
    }

    /**
     * The kerning the letters (not the spaces) of a line gained over their natural setting.
     *
     * @return list<float>
     */
    private function letterKerning(Line $line, Justification $justification): array
    {
        $added   = self::added($line, $this->natural(self::TEXT, $justification));
        $letters = [];
        foreach (self::glyphs($line) as $i => $glyph) {
            if (0x20 === $glyph->codePoint) {
                continue;
            }
            $letters[] = $added[$i] ?? 0.0;
        }

        return $letters;
    }

    /** The letters' stretch over the line: 10% of a space after all eight glyphs but the last. */
    private function letterStretch(): float
    {
        return 7 * 0.1 * $this->space();
    }

    private function line(string $text, float $available, Alignment $alignment, Justification $justification): Line
    {
        $style = $this->style->merge(new TextStyle(justification: $justification));
        $items = (new Itemiser())->itemise([[$text, $style]]);

        return LineAssembler::line($items, null, $style, [$alignment, 0], 0.0, $available, [0, 0], false);
    }

    private function natural(string $text, Justification $justification): Line
    {
        return $this->line($text, 300.0, Alignment::Left, $justification);
    }

    private function space(): float
    {
        return $this->serif->width(' ', 10.0);
    }

    private function width(): float
    {
        return $this->serif->width(self::TEXT, 10.0);
    }

    /** The two word spaces' stretch, to 133%. */
    private function wordStretch(): float
    {
        return 2 * 0.33 * $this->space();
    }
}
