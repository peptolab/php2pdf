<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font;

use Php2Pdf\Font\CodeSpace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function substr_count;

/** Codes take one byte for the first 192 glyphs and two for the next 16,384. */
#[Group('unit')]
final class CodeSpaceTest extends TestCase
{
    /** @return array<string, array{0: int, 1: string}> */
    public static function bytesProvider(): array
    {
        return [
            'the first code'               => [0, "\x00"],
            'the last one-byte code'       => [191, "\xBF"],
            'the first two-byte code'      => [192, "\xC0\x00"],
            'the last code of a lead byte' => [447, "\xC0\xFF"],
            'the next lead byte'           => [448, "\xC1\x00"],
            'the last code'                => [16_575, "\xFF\xFF"],
        ];
    }

    /** @return array<string, array{0: int, 1: bool}> */
    public static function fitsProvider(): array
    {
        return [
            'as many glyphs as codes' => [16_576, true],
            'one glyph more'          => [16_577, false],
        ];
    }

    /** @return array<string, array{0: int, 1: int}> glyphs a font uses, and the lead bytes its codes need */
    public static function leadsProvider(): array
    {
        return [
            'one-byte codes only'     => [192, 0],
            'the first two-byte code' => [193, 1],
            'a full lead byte'        => [448, 1],
            'into the next lead byte' => [449, 2],
            'every code'              => [16_576, 64],
        ];
    }

    #[Test]
    #[DataProvider('bytesProvider')]
    public function aCodeIsWrittenInOneByteOrTwo(int $code, string $bytes): void
    {
        self::assertSame($bytes, CodeSpace::bytes($code));
    }

    #[Test]
    #[DataProvider('fitsProvider')]
    public function aFontFitsWhenEveryGlyphHasACode(int $glyphs, bool $fits): void
    {
        self::assertSame($fits, CodeSpace::fits($glyphs));
    }

    #[Test]
    #[DataProvider('leadsProvider')]
    public function aFontNeedsAsManyLeadBytesAsItsTwoByteCodesFill(int $glyphs, int $leads): void
    {
        self::assertSame($leads, CodeSpace::leads($glyphs));
    }

    #[Test]
    public function theCMapMapsEachCodeToTheCidOfTheSameNumber(): void
    {
        $cmap = CodeSpace::cmap(64);

        self::assertSame([1, 1, 1, 1], [
            substr_count($cmap, needle: "65 begincidrange\n<00> <BF> 0\n"),
            substr_count($cmap, needle: "<C000> <C0FF> 192\n"),
            substr_count($cmap, needle: "<C100> <C1FF> 448\n"),
            substr_count($cmap, needle: "<FF00> <FFFF> 16320\nendcidrange\n"),
        ]);
    }

    #[Test]
    public function theCMapOfAFontWithOnlyOneByteCodesHasOneRange(): void
    {
        self::assertSame(1, substr_count(CodeSpace::cmap(0), needle: "1 begincidrange\n<00> <BF> 0\nendcidrange\n"));
    }
}
