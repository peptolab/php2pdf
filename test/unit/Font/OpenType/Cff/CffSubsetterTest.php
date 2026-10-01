<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\OpenType\Cff;

use Php2Pdf\Font\OpenType\Cff\CffSubsetter;
use Php2Pdf\Font\OpenType\Cff\CffTable;
use Php2Pdf\Font\OpenType\Cff\Dict;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2PdfTest\Support\CffBuilder;
use Php2PdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_key_exists;
use function array_keys;
use function array_slice;
use function count;
use function max;
use function mb_ord;
use function mb_str_split;
use function strlen;

#[Group('unit')]
final class CffSubsetterTest extends TestCase
{
    private const string ENDCHAR = "\x0E";
    private const string RETURN  = "\x0B";

    /** Pushes -107 and calls local subr 0. */
    private const string CALL_FIRST_LOCAL = "\x20\x0A";

    /** Pushes -107 and calls global subr 0. */
    private const string CALL_FIRST_GLOBAL = "\x20\x1D";

    private static function cidTestFont(): OpenTypeFont
    {
        return OpenTypeFont::fromFile(PdfTools::assetPath('fonts/PhpPdfCidTest.otf'));
    }

    /** @return array<int, true> */
    private static function glyphs(OpenTypeFont $font, string $text): array
    {
        $glyphs = [];
        foreach (mb_str_split($text) as $char) {
            $glyphs[$font->glyphId(mb_ord($char))] = true;
        }

        return $glyphs;
    }

    private static function sourceSans(): OpenTypeFont
    {
        return OpenTypeFont::fromFile(PdfTools::assetPath('fonts/SourceSans3-Regular.otf'));
    }

    /** A distinguishable subroutine: an rmoveto by $dx, then return. */
    private static function subr(int $dx): string
    {
        return Dict::int($dx) . Dict::int(0) . "\x15" . self::RETURN;
    }

    #[Test]
    public function aFontWithoutLocalSubrsStaysWithout(): void
    {
        $subset = CffTable::parse(CffSubsetter::subset(CffBuilder::nameKeyed([self::ENDCHAR, self::ENDCHAR]), [
            1 => true,
        ]));

        self::assertSame([[], [], 2], [
            $subset->fonts[0]['private'] ?? null,
            $subset->fonts[0]['subrs'] ?? null,
            count($subset->charStrings),
        ]);
    }

    #[Test]
    public function aMatrixSharedByEveryFontDictMovesToTheTopDict(): void
    {
        $font   = self::cidTestFont();
        $subset = CffTable::parse(CffSubsetter::subset($font->table('CFF ') ?? '', self::glyphs($font, 'A')));

        self::assertSame(
            [[0.001, 0, 0, 0.001, 0, 0], false, false],
            [
                $subset->top[CffTable::OP_FONT_MATRIX]?->values,
                array_key_exists(CffTable::OP_FONT_MATRIX, $subset->fonts[0]['dict'] ?? []),
                array_key_exists(CffTable::OP_FONT_MATRIX, $subset->fonts[1]['dict'] ?? []),
            ],
        );
    }

    #[Test]
    public function aNameKeyedFontMatrixStaysInTheTopDict(): void
    {
        $matrix = Dict::int(0) . Dict::int(1);
        $cff    = CffBuilder::nameKeyed([self::ENDCHAR], top: [CffTable::OP_FONT_MATRIX => $matrix]);
        $subset = CffTable::parse(CffSubsetter::subset($cff, []));

        self::assertSame(
            [$matrix, false],
            [
                $subset->top[CffTable::OP_FONT_MATRIX]?->operands,
                array_key_exists(CffTable::OP_FONT_MATRIX, $subset->fonts[0]['dict'] ?? []),
            ],
        );
    }

    #[Test]
    public function aNonIdentityTopMatrixKeepsTheFontDictMatrices(): void
    {
        $top = Dict::int(2) . Dict::int(0) . Dict::int(0) . Dict::int(2) . Dict::int(0) . Dict::int(0);
        $fd  = Dict::int(1) . Dict::int(0);
        $cff = CffBuilder::cidKeyed(
            [self::ENDCHAR],
            "\x00\x00",
            [[]],
            fdExtra: [[CffTable::OP_FONT_MATRIX => $fd]],
            top: [CffTable::OP_FONT_MATRIX => $top],
        );
        $subset = CffTable::parse(CffSubsetter::subset($cff, []));

        self::assertSame(
            [$top, $fd],
            [
                $subset->top[CffTable::OP_FONT_MATRIX]?->operands,
                $subset->fonts[0]['dict'][CffTable::OP_FONT_MATRIX]?->operands,
            ],
        );
    }

    #[Test]
    public function cidKeyedFontsKeepTheirFontDictsAndSelection(): void
    {
        $font     = self::cidTestFont();
        $original = CffTable::parse($font->table('CFF ') ?? '');
        $used     = self::glyphs($font, 'Az');
        $subset   = CffTable::parse(CffSubsetter::subset($font->table('CFF ') ?? '', $used));
        $glyphs   = count($subset->charStrings);

        self::assertSame(
            [2, array_slice($original->fdSelect, offset: 0, length: $glyphs), [391, 392, 0]],
            [count($subset->fonts), $subset->fdSelect, $subset->top[CffTable::OP_ROS]?->values],
        );
    }

    #[Test]
    public function cidKeyedFontsWithoutMatricesGetNone(): void
    {
        $cff    = CffBuilder::cidKeyed([self::ENDCHAR, self::ENDCHAR], "\x00\x00\x00", [[]]);
        $subset = CffTable::parse(CffSubsetter::subset($cff, [1 => true]));

        self::assertSame(
            [false, false],
            [
                array_key_exists(CffTable::OP_FONT_MATRIX, $subset->top),
                array_key_exists(CffTable::OP_FONT_MATRIX, $subset->fonts[0]['dict'] ?? []),
            ],
        );
    }

    #[Test]
    public function differingFontDictMatricesStayInTheirFontDicts(): void
    {
        $small = Dict::int(1) . Dict::int(0);
        $large = Dict::int(2) . Dict::int(0);
        $cff   = CffBuilder::cidKeyed(
            [self::ENDCHAR, self::ENDCHAR],
            "\x00\x00\x01",
            [[], []],
            fdExtra: [[CffTable::OP_FONT_MATRIX => $small], [CffTable::OP_FONT_MATRIX => $large]],
        );
        $subset = CffTable::parse(CffSubsetter::subset($cff, [1 => true]));

        self::assertSame(
            [false, $small, $large],
            [
                array_key_exists(CffTable::OP_FONT_MATRIX, $subset->top),
                $subset->fonts[0]['dict'][CffTable::OP_FONT_MATRIX]?->operands,
                $subset->fonts[1]['dict'][CffTable::OP_FONT_MATRIX]?->operands,
            ],
        );
    }

    #[Test]
    public function glyphIdsOutsideTheFontAreIgnored(): void
    {
        $cff    = CffBuilder::nameKeyed([self::ENDCHAR, self::ENDCHAR]);
        $subset = CffTable::parse(CffSubsetter::subset($cff, [-1 => true, 5 => true]));

        self::assertSame([self::ENDCHAR], $subset->charStrings);
    }

    #[Test]
    public function keepsOnlyTheSubroutinesTheGlyphsCall(): void
    {
        $cff = CffBuilder::nameKeyed(
            [
                self::ENDCHAR,
                self::CALL_FIRST_GLOBAL . self::CALL_FIRST_LOCAL . self::ENDCHAR,
                "\x21\x0A" . self::ENDCHAR,
            ],
            [self::subr(1), self::subr(2)],
            [self::subr(3), self::subr(4)],
        );
        $subset = CffTable::parse(CffSubsetter::subset($cff, [1 => true]));

        self::assertSame(
            [[self::subr(1), self::RETURN], [self::subr(3), self::RETURN]],
            [$subset->globalSubrs, $subset->fonts[0]['subrs'] ?? null],
        );
    }

    #[Test]
    public function keepsTheUsedCharStringsUnchangedAndEmptiesTheRest(): void
    {
        $font     = self::sourceSans();
        $original = CffTable::parse($font->table('CFF ') ?? '');
        $used     = self::glyphs($font, 'Hel');
        $subset   = CffTable::parse(CffSubsetter::subset($font->table('CFF ') ?? '', $used));

        $expected = [];
        for ($gid = 0, $n = max(array_keys($used)) + 1; $gid < $n; ++$gid) {
            $expected[] = 0 === $gid || array_key_exists($gid, $used) ? $original->charStrings[$gid] : self::ENDCHAR;
        }
        self::assertSame($expected, $subset->charStrings);
    }

    #[Test]
    public function localSubrsArePrunedPerFontDict(): void
    {
        $cff = CffBuilder::cidKeyed(
            [self::ENDCHAR, self::CALL_FIRST_LOCAL . self::ENDCHAR, self::CALL_FIRST_LOCAL . self::ENDCHAR],
            "\x00\x00\x00\x01",
            [[self::subr(1)], [self::subr(2)]],
        );
        $subset = CffTable::parse(CffSubsetter::subset($cff, [1 => true]));

        self::assertSame(
            [[self::subr(1)], [self::RETURN]],
            [$subset->fonts[0]['subrs'] ?? null, $subset->fonts[1]['subrs'] ?? null],
        );
    }

    #[Test]
    public function makesANameKeyedFontCidKeyedWithItsDefaultMatrix(): void
    {
        $font   = self::sourceSans();
        $subset = CffTable::parse(CffSubsetter::subset($font->table('CFF ') ?? '', self::glyphs($font, 'H')));

        self::assertSame(
            [true, [391, 392, 0], false, false],
            [
                $subset->cidKeyed,
                $subset->top[CffTable::OP_ROS]?->values,
                array_key_exists(CffTable::OP_FONT_MATRIX, $subset->top),
                array_key_exists(CffTable::OP_FONT_MATRIX, $subset->fonts[0]['dict'] ?? []),
            ],
            'Source Sans has the default matrix, so neither DICT writes one.',
        );
    }

    #[Test]
    public function shrinksTheFontToAFractionOfItsSize(): void
    {
        $font = self::sourceSans();
        $cff  = $font->table('CFF ') ?? '';

        self::assertLessThan(
            strlen($cff) / 10,
            strlen(CffSubsetter::subset($cff, self::glyphs($font, 'Invoice total'))),
        );
    }
}
