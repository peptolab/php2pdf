<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font;

use Php2Pdf\Font\CodeSpace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function substr_count;

/**
 * Codes take one byte for the first 191 CIDs and two for the next 16,384; the space, CID 1, is
 * code 32.
 */
#[Group('unit')]
final class CodeSpaceTest extends TestCase
{
    /** @return array<string, array{0: int, 1: string}> */
    public static function bytesProvider(): array
    {
        return [
            'the first code'               => [0, "\x00"],
            'the space'                    => [1, ' '],
            'the CID after the space'      => [2, "\x02"],
            'the last code before 32'      => [31, "\x1F"],
            'the CID that skips 32'        => [32, '!'],
            'the last one-byte code'       => [190, "\xBF"],
            'the first two-byte code'      => [191, "\xC0\x00"],
            'the last code of a lead byte' => [446, "\xC0\xFF"],
            'the next lead byte'           => [447, "\xC1\x00"],
            'the last code'                => [16_574, "\xFF\xFF"],
        ];
    }

    /** @return array<string, array{0: int, 1: bool}> */
    public static function fitsProvider(): array
    {
        return [
            'as many glyphs as codes' => [16_575, true],
            'one glyph more'          => [16_576, false],
        ];
    }

    /** @return array<string, array{0: int, 1: int}> glyphs a font uses, and the lead bytes its codes need */
    public static function leadsProvider(): array
    {
        return [
            'one-byte codes only'     => [191, 0],
            'the first two-byte code' => [192, 1],
            'a full lead byte'        => [447, 1],
            'into the next lead byte' => [448, 2],
            'every code'              => [16_575, 64],
        ];
    }

    #[Test]
    #[DataProvider('bytesProvider')]
    public function aCidIsWrittenInOneByteOrTwo(int $cid, string $bytes): void
    {
        self::assertSame($bytes, CodeSpace::bytes($cid));
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
    public function theCMapMapsEachCodeToTheCidWrittenWithIt(): void
    {
        $cmap = CodeSpace::cmap(64);

        self::assertSame([1, 1, 1, 1], [
            substr_count($cmap, needle: "67 begincidrange\n<00> <1F> 0\n<20> <20> 1\n<21> <BF> 32\n"),
            substr_count($cmap, needle: "<C000> <C0FF> 191\n"),
            substr_count($cmap, needle: "<C100> <C1FF> 447\n"),
            substr_count($cmap, needle: "<FF00> <FFFF> 16319\nendcidrange\n"),
        ]);
    }

    #[Test]
    public function theCMapOfAFontWithOnlyOneByteCodesHasTheOneByteRanges(): void
    {
        self::assertSame(1, substr_count(
            CodeSpace::cmap(0),
            needle: "3 begincidrange\n<00> <1F> 0\n<20> <20> 1\n<21> <BF> 32\nendcidrange\n",
        ));
    }
}
