<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Canvas;

use Php2Pdf\Canvas\TextOperators;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Font\PdfFontInterface;
use Php2Pdf\Font\StandardFont;
use Php2Pdf\Font\TextOptions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function chr;
use function implode;
use function ord;

/** Glyph offsets in the text-showing operators (#33); Helvetica's codes are its characters. */
#[Group('unit')]
final class TextOperatorsTest extends TestCase
{
    /** @return array<string, array{0: list<Glyph>, 1: TextOptions, 2: float, 3: string}> */
    public static function adjustmentProvider(): array
    {
        $g = static fn(string $c, float $kerning = 0.0): Glyph => (new Glyph(ord($c), ord($c), 500))->withKerning(
            $kerning,
        );
        $space = new Glyph(32, 32, 278);
        $nbsp  = new Glyph(32, 0xA0, 278);

        return [
            'tracking'                    => [
                [$g('A', -30.0), $g('V'), $space, $g('B')],
                new TextOptions(tracking: 20.0),
                10.0,
                '[(A) 10 (V) -20 ( ) -20 (B) -20] TJ',
            ],
            'word spacing'                => [
                [$g('A'), $space, $g('B', -15.5), $nbsp, $g('C')],
                new TextOptions(wordSpacing: 1.37),
                9.5,
                '[(A ) -144.21 (B) 15.5 ( ) -144.21 (C)] TJ',
            ],
            'tracking and word spacing'   => [
                [$g('A'), $space, $space, $g('B')],
                new TextOptions(
                    wordSpacing: 2.0,
                    tracking: -12.5,
                ),
                12.0,
                '[(A) 12.5 ( ) -154.17 ( ) -154.17 (B) 12.5] TJ',
            ],
            'escaped codes'               => [
                [$g('('), $g(')', -40.0), $g('\\'), $g("\r"), $g('x')],
                TextOptions::default(),
                10.0,
                '[(\\(\\)) 40 (\\\\\\rx)] TJ',
            ],
            'offsets with spacing'        => [
                [
                    $g('A', -20.0),
                    $space,
                    $g('B')->withOffset(35.0, 0.0),
                    $space,
                    $g('C')->withOffset(-10.0, 250.0),
                    $g('D'),
                ],
                new TextOptions(
                    wordSpacing: 1.5,
                    tracking: 10.0,
                    rise: 1.0,
                ),
                10.0,
                '[(A) 10 ( ) -195 (B) 25 ( )] TJ 3.5 Ts [-150 (C)] TJ 1 Ts [-20 (D) -10] TJ',
            ],
            'a raised glyph at the start' => [
                [$g('A')->withOffset(0.0, 400.0), $g('B', -25.0), $space, $g('C')],
                new TextOptions(wordSpacing: 3.0),
                8.0,
                '3.2 Ts (A) Tj 0 Ts [(B) 25 ( ) -375 (C)] TJ',
            ],
            'no glyphs'                   => [[], TextOptions::default(), 10.0, '[] TJ'],
            'trailing kerning'            => [[$g('A'), $g('B', -50.0)], TextOptions::default(), 10.0, '[(AB) 50] TJ'],
        ];
    }

    /** @return array<string, array{0: list<Glyph>, 1: float, 2: string}> */
    public static function runProvider(): array
    {
        $a = new Glyph(65, 65, 667);
        $b = new Glyph(66, 66, 667);

        return [
            'no offsets'                 => [[$a, $b], 0.0, '(AB) Tj'],
            'kerning'                    => [[$a->withKerning(-50.0), $b], 0.0, '[(A) 50 (B)] TJ'],
            'a glyph moved right'        => [[$a, $b->withOffset(120.0, 0.0)], 0.0, '[(A) -120 (B) 120] TJ'],
            'an offset meets kerning'    => [
                [$a->withKerning(-50.0), $b->withOffset(120.0, 0.0)],
                0.0,
                '[(A) -70 (B) 120] TJ',
            ],
            'a glyph raised'             => [[$a, $b->withOffset(0.0, 300.0)], 0.0, '(A) Tj 3 Ts (B) Tj 0 Ts'],
            'a glyph raised over a rise' => [
                [$a, $b->withOffset(0.0, -150.0), $a],
                2.0,
                '(A) Tj 0.5 Ts (B) Tj 2 Ts (A) Tj',
            ],
            'raised and moved'           => [
                [$a, $b->withOffset(-200.0, 300.0)],
                0.0,
                '(A) Tj 3 Ts [200 (B) -200] TJ 0 Ts',
            ],
        ];
    }

    /**
     * Tracking, word spacing and offsets together, twice over so the second time reads the
     * remembered glyph codes and adjustments.
     *
     * @param list<Glyph> $glyphs
     */
    #[Test]
    #[DataProvider('adjustmentProvider')]
    public function adjustmentsAddUpBetweenGlyphs(
        array $glyphs,
        TextOptions $options,
        float $size,
        string $expected,
    ): void {
        $doc  = new Document(new MemoryOutput());
        $font = $doc->fonts()->standard(StandardFont::Helvetica);

        self::assertSame($expected, TextOperators::operation(new GlyphRun($glyphs), $font, $size, $options));
        self::assertSame($expected, TextOperators::operation(new GlyphRun($glyphs), $font, $size, $options));
    }

    #[Test]
    public function adjustmentsStayRightOnceTheirMemoStartsOver(): void
    {
        $doc    = new Document(new MemoryOutput());
        $font   = $doc->fonts()->standard(StandardFont::Helvetica);
        $glyphs = [];
        for ($i = 1; $i <= 5000; ++$i) {
            $glyphs[] = (new Glyph(65, 65, 667))->withKerning(-$i / 4);
        }

        self::assertStringEndsWith(
            '(A) 1249.75 (A) 1250] TJ',
            TextOperators::operation(new GlyphRun($glyphs), $font, 10.0, TextOptions::default()),
        );
    }

    #[Test]
    public function aFontOfAnotherKindEncodesEachStringWhole(): void
    {
        $font = $this->createStub(PdfFontInterface::class);
        $font->method('encode')->willReturnCallback(
            /** @param list<int> $ids */
            static fn(array $ids): string => '<' . implode(array_map(chr(...), $ids)) . '>',
        );
        $run = new GlyphRun([
            (new Glyph(65, 65, 667))->withKerning(-50.0),
            new Glyph(66, 66, 667),
            new Glyph(67, 67, 667),
        ]);

        self::assertSame(
            '[(<A>) 50 (<BC>)] TJ',
            TextOperators::operation($run, $font, 10.0, TextOptions::default()),
        );
    }

    /** @param list<Glyph> $glyphs */
    #[Test]
    #[DataProvider('runProvider')]
    public function offsetsMoveOnlyTheirGlyph(array $glyphs, float $rise, string $expected): void
    {
        $doc  = new Document(new MemoryOutput());
        $font = $doc->fonts()->standard(StandardFont::Helvetica);

        self::assertSame($expected, TextOperators::operation(
            new GlyphRun($glyphs),
            $font,
            10.0,
            new TextOptions(rise: $rise),
        ));
    }
}
