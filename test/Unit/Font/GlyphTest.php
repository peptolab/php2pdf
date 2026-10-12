<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font;

use Php2Pdf\Font\Glyph;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** A glyph stands for its components, its own character, or nothing. */
#[Group('unit')]
final class GlyphTest extends TestCase
{
    /** @return array<string, array{0: Glyph, 1: int}> */
    public static function lengthProvider(): array
    {
        return [
            'a glyph for one character'               => [new Glyph(36, 0x41, 600), 1],
            'a ligature for its components'           => [new Glyph(90, 0x66, 900, components: [0x66, 0x69]), 2],
            'a glyph a character became among others' => [new Glyph(12, -1, 0), 0],
        ];
    }

    #[Test]
    #[DataProvider('lengthProvider')]
    public function aGlyphStandsForAsManyCharactersAsItShows(Glyph $glyph, int $length): void
    {
        self::assertSame($length, $glyph->length());
    }
}
