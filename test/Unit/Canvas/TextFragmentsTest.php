<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Canvas;

use Php2Pdf\Canvas\TextFragments;
use Php2Pdf\Canvas\TextOperators;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Font\StandardFont;
use Php2Pdf\Font\TextOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function count;
use function ord;

/**
 * Runs shown from the runs they were joined from show as they do from their glyphs; Helvetica's
 * codes are its characters.
 */
#[Group('unit')]
final class TextFragmentsTest extends TestCase
{
    /**
     * Runs joined from pieces, each a list of glyphs, with the kerning a stretched word space
     * gives some pieces' last glyph, by position.
     *
     * @return array<string, array{0: list<list<Glyph>>, 1: array<int, float>}>
     */
    public static function piecesProvider(): array
    {
        $g = static fn(string $c, float $kerning = 0.0): Glyph => (new Glyph(ord($c), ord($c), 500))->withKerning(
            $kerning,
        );
        $space  = new Glyph(32, 32, 278);
        $kerned = $space->withKerning(-18.0);

        return [
            'words and spaces'                     => [[[$g('A'), $g('B')], [$space], [$g('C')]], []],
            'one piece'                            => [[[$g('A'), $g('B')]], []],
            'kerning inside a word'                => [
                [[$g('A', -30.0), $g('V'), $g('A', -12.5), $g('W')], [$space], [$g('T', -40.0), $g('o')]],
                [],
            ],
            'kerning between pieces'               => [[[$g('A'), $g('T', -50.0)], [$kerned], [$g('A')]], []],
            'trailing kerning'                     => [[[$g('A')], [$g('B', -50.0)]], []],
            'empty pieces carry the adjustment'    => [[[$g('A', -20.0)], [], [], [$g('B')], []], []],
            'empty pieces first'                   => [[[], [$g('A')], [], [$g('B', -5.0)]], []],
            'a stretched space'                    => [[[$g('A')], [$space], [$g('B')]], [1 => 120.5]],
            'a stretched kerned space'             => [[[$g('A')], [$kerned], [$g('B')]], [1 => 82.0]],
            'a space stretched back to no kerning' => [[[$g('A')], [$kerned], [$g('B')]], [1 => 0.0]],
            'a stretched space with a middle'      => [
                [[$g('A', -10.0), $g('V')], [$space, $kerned], [$g('B')]],
                [1 => -7.25],
            ],
            'a stretched space at the end'         => [[[$g('A')], [$space]], [1 => 33.0]],
            'stretched spaces twice'               => [
                [[$g('A')], [$space], [$g('B')], [$space], [$g('C')]],
                [1 => 40.0, 3 => 40.0],
            ],
            'escaped codes'                        => [
                [[$g('('), $g(')', -40.0)], [$g('\\'), $g("\r")], [$g('x')]],
                [],
            ],
            'a piece repeated'                     => [
                [[$g('A', -30.0), $g('V')], [$space], [$g('A', -30.0), $g('V')]],
                [],
            ],
        ];
    }

    /** @return array<string, array{0: TextOptions, 1: float}> */
    public static function spacedProvider(): array
    {
        return [
            'tracking'     => [new TextOptions(tracking: 5.0), 10.0],
            'word spacing' => [new TextOptions(wordSpacing: 1.0), 10.0],
        ];
    }

    /**
     * The glyphs of pieces joined, as a line joins its items' glyphs.
     *
     * @param list<list<Glyph>> $pieces
     * @param array<int, float> $lastKernings
     */
    private static function joined(array $pieces, array $lastKernings): GlyphRun
    {
        $glyphs = [];
        foreach ($pieces as $index => $piece) {
            $kerning = $lastKernings[$index] ?? null;
            $last    = count($piece) - 1;
            foreach ($piece as $k => $glyph) {
                $glyphs[] = null !== $kerning && $k === $last ? $glyph->withKerning($kerning) : $glyph;
            }
        }

        return new GlyphRun($glyphs);
    }

    /**
     * Shown from its pieces, a run shows as it does from its glyphs: not the first time, when
     * its pieces are new, but the second, and the third from what is remembered of each piece.
     *
     * @param list<list<Glyph>> $pieces
     * @param array<int, float> $lastKernings
     */
    #[Test]
    #[DataProvider('piecesProvider')]
    public function piecesShowAsTheirJoinedGlyphs(array $pieces, array $lastKernings): void
    {
        $doc       = new Document(new MemoryOutput());
        $font      = $doc->fonts()->standard(StandardFont::Helvetica);
        $fragments = new TextFragments();
        $runs      = array_map(
            /** @param list<Glyph> $glyphs */
            static fn(array $glyphs): GlyphRun => new GlyphRun($glyphs),
            $pieces,
        );
        $options  = TextOptions::default();
        $expected = TextOperators::operation(self::joined($pieces, $lastKernings), $font, 10.0, $options);

        self::assertSame([null, [$expected, true], [$expected, true]], [
            $fragments->show($runs, $lastKernings, $font, 10.0, $options),
            $fragments->show($runs, $lastKernings, $font, 10.0, $options),
            $fragments->show($runs, $lastKernings, $font, 10.0, $options),
        ]);
    }

    /**
     * Many runs shown after one kept piece fill the memo, which starts over; every run is still
     * shown as its glyphs are, once it has been shown before.
     */
    #[Test]
    public function piecesStayRightOnceTheirMemoStartsOver(): void
    {
        $doc       = new Document(new MemoryOutput());
        $font      = $doc->fonts()->standard(StandardFont::Helvetica);
        $fragments = new TextFragments();
        $options   = TextOptions::default();
        $kept      = new GlyphRun([(new Glyph(65, 65, 667))->withKerning(-30.0), new Glyph(86, 86, 667)]);
        $wrong     = [];
        for ($i = 0; $i < 20_000; ++$i) {
            $run      = new GlyphRun([(new Glyph(66, 66, 667))->withKerning(-$i / 8)]);
            $expected = TextOperators::operation(
                self::joined([$kept->glyphs, $run->glyphs], []),
                $font,
                10.0,
                $options,
            );
            // New the first time, and again the second when the memo has just started over.
            $shown =
                $fragments->show([$kept, $run], [], $font, 10.0, $options)
                    ?? $fragments->show([$kept, $run], [], $font, 10.0, $options)
                    ?? $fragments->show([$kept, $run], [], $font, 10.0, $options);
            if ([$expected, true] === $shown) {
                continue;
            }

            $wrong[] = $i;
        }

        self::assertSame([], $wrong, 'Runs shown other than as their glyphs.');
    }

    #[Test]
    public function piecesTellWhenTheFontsMapCannotGiveTheirTextBack(): void
    {
        $doc       = new Document(new MemoryOutput());
        $font      = $doc->fonts()->standard(StandardFont::Helvetica);
        $fragments = new TextFragments();
        $options   = TextOptions::default();
        $first     = new GlyphRun([new Glyph(65, 65, 667)]);
        $other     = new GlyphRun([new Glyph(65, 66, 667)]);

        self::assertSame([null, ['(A) Tj', true], null, ['(AA) Tj', false], ['(AA) Tj', false]], [
            $fragments->show([$first], [], $font, 10.0, $options),
            $fragments->show([$first], [], $font, 10.0, $options),
            $fragments->show([$first, $other], [], $font, 10.0, $options),
            $fragments->show([$first, $other], [], $font, 10.0, $options),
            $fragments->show([$first, $other], [], $font, 10.0, $options),
        ]);
    }

    #[Test]
    public function piecesWithOffsetsAreLeftToTheirGlyphs(): void
    {
        $doc       = new Document(new MemoryOutput());
        $font      = $doc->fonts()->standard(StandardFont::Helvetica);
        $fragments = new TextFragments();
        $plain     = new GlyphRun([new Glyph(65, 65, 667), new Glyph(32, 32, 278)]);
        $moved     = new GlyphRun([new Glyph(66, 66, 667), (new Glyph(67, 67, 667))->withOffset(0.0, 200.0)]);

        self::assertSame([null, null, null], [
            $fragments->show([$plain, $moved], [], $font, 10.0, TextOptions::default()),
            $fragments->show([$plain, $moved], [], $font, 10.0, TextOptions::default()),
            $fragments->show([$plain, $moved], [], $font, 10.0, TextOptions::default()),
        ]);
    }

    #[Test]
    #[DataProvider('spacedProvider')]
    public function piecesWithTrackingOrWordSpacingAreLeftToTheirGlyphs(TextOptions $options, float $size): void
    {
        $doc       = new Document(new MemoryOutput());
        $font      = $doc->fonts()->standard(StandardFont::Helvetica);
        $fragments = new TextFragments();
        $plain     = new GlyphRun([new Glyph(65, 65, 667), new Glyph(32, 32, 278)]);
        $fragments->show([$plain], [], $font, $size, TextOptions::default());
        $fragments->show([$plain], [], $font, $size, TextOptions::default());

        self::assertNull($fragments->show([$plain], [], $font, $size, $options));
    }

    /** Word spacing moves nothing at a size of 0, so the pieces are shown as they are. */
    #[Test]
    public function wordSpacingAtNoSizeLeavesPiecesShown(): void
    {
        $doc       = new Document(new MemoryOutput());
        $font      = $doc->fonts()->standard(StandardFont::Helvetica);
        $fragments = new TextFragments();
        $plain     = new GlyphRun([new Glyph(65, 65, 667), new Glyph(32, 32, 278)]);
        $fragments->show([$plain], [], $font, 0.0, TextOptions::default());

        self::assertSame(
            ['(A ) Tj', true],
            $fragments->show([$plain], [], $font, 0.0, new TextOptions(wordSpacing: 1.0)),
        );
    }
}
