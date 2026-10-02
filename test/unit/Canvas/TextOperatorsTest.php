<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Canvas;

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

/** Glyph offsets in the text-showing operators (#33); Helvetica's codes are its characters. */
#[Group('unit')]
final class TextOperatorsTest extends TestCase
{
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
