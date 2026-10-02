<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font;

use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Font\TextMapping;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class TextMappingTest extends TestCase
{
    /** @return array<string, array{list<GlyphRun>, GlyphRun, bool}> */
    public static function runProvider(): array
    {
        $space = new GlyphRun([new Glyph(3, 0x20, 600)]);

        return [
            'glyphs mapped for the first time'      => [
                [],
                new GlyphRun([new Glyph(5, 0x61, 500), new Glyph(3, 0x20, 600)]),
                true,
            ],
            'glyphs mapped to the same text before' => [[$space], $space, true],
            'a glyph mapped to other text before'   => [[$space], new GlyphRun([new Glyph(3, 0x2D, 600)]), false],
            'a no-break space on the space glyph'   => [[$space], new GlyphRun([new Glyph(3, 0xA0, 600)]), true],
            'an invisible joiner on the space'      => [[], new GlyphRun([new Glyph(3, 0x200D, 600)]), false],
            'a character the font has no glyph for' => [[], new GlyphRun([new Glyph(0, 0x61, 500)]), false],
            'a glyph that stands for no text'       => [[], new GlyphRun([new Glyph(7, -1, 0)]), true],
        ];
    }

    #[Test]
    public function anInvisibleCharacterLeavesItsGlyphUnmapped(): void
    {
        $mapping = new TextMapping();
        $mapping->record(new GlyphRun([new Glyph(3, 0x200D, 600)]));
        $mapping->record(new GlyphRun([new Glyph(3, 0x20, 600)]));

        self::assertSame([3 => [0x20]], $mapping->map());
    }

    /** @param list<GlyphRun> $earlier */
    #[Test]
    #[DataProvider('runProvider')]
    public function aRunSaysWhetherTheMapGivesBackItsText(array $earlier, GlyphRun $run, bool $faithful): void
    {
        $mapping = new TextMapping();
        foreach ($earlier as $before) {
            $mapping->record($before);
        }

        self::assertSame($faithful, $mapping->record($run));
    }
}
