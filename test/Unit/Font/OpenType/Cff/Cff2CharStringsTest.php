<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\OpenType\Cff;

use Php2Pdf\Exception\ParseException;
use Php2Pdf\Font\OpenType\Cff\Cff2CharStrings;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function bin2hex;
use function chr;
use function str_repeat;

/**
 * CFF2 charstrings become Type 2 charstrings for the default instance (#39). Numbers are written
 * in their one-byte form: chr(v + 139).
 */
#[Group('unit')]
final class Cff2CharStringsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: list<int>, 2: string}> */
    public static function glyphProvider(): array
    {
        return [
            'blend keeps the defaults over one region'     => [
                self::n(10) . self::n(20) . self::n(1) . self::n(2) . self::n(2) . "\x10\x15",
                [1],
                self::n(10) . self::n(20) . "\x15\x0E",
            ],
            'blend over two regions'                       => [
                self::n(5) . self::n(7) . self::n(8) . self::n(1) . "\x10\x16",
                [2],
                self::n(5) . "\x16\x0E",
            ],
            'vsindex switches the region count'            => [
                self::n(1) . "\x0F" . self::n(5) . self::n(7) . self::n(8) . self::n(1) . "\x10\x16",
                [1, 2],
                self::n(5) . "\x16\x0E",
            ],
            'a hint mask after nine stems has two bytes'   => [
                str_repeat(self::n(1) . self::n(2), times: 9) . "\x12\x13\xFF\x80" . self::n(5) . "\x16",
                [0],
                str_repeat(self::n(1) . self::n(2), times: 9) . "\x12\x13\xFF\x80" . self::n(5) . "\x16\x0E",
            ],
            'values before a hint mask are implicit stems' => [
                self::n(1) . self::n(2) . "\x01" . self::n(3) . self::n(4) . "\x13\xC0" . self::n(5) . "\x16",
                [0],
                self::n(1) . self::n(2) . "\x01" . self::n(3) . self::n(4) . "\x13\xC0" . self::n(5) . "\x16\x0E",
            ],
        ];
    }

    private static function n(int $value): string
    {
        return chr($value + 139);
    }

    #[Test]
    public function aBlendWithoutItsOperandsIsAnError(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('CFF2 blend has too few operands.');
        (new Cff2CharStrings([], [[]], [1], [0]))->glyph(self::n(1) . self::n(1) . "\x10", 0);
    }

    #[Test]
    public function aMissingSubroutineIsAnError(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('CFF2 subroutine 107 of 0 does not exist.');
        (new Cff2CharStrings([], [[]], [0], [0]))->glyph(self::n(0) . "\x1D", 0);
    }

    /** @param list<int> $regions */
    #[Test]
    #[DataProvider('glyphProvider')]
    public function convertsAGlyph(string $cff2, array $regions, string $type2): void
    {
        $converter = new Cff2CharStrings([], [[]], $regions, [0]);

        self::assertSame(bin2hex($type2), bin2hex($converter->glyph($cff2, 0)));
    }

    #[Test]
    public function stemsDeclaredInASubroutineCountOnEveryCall(): void
    {
        $stems     = str_repeat(self::n(1) . self::n(2), times: 5) . "\x12";
        $converter = new Cff2CharStrings([], [[$stems]], [0], [0]);
        $converter->glyph(self::n(-107) . "\x0A", 0);
        $glyph = $converter->glyph(
            self::n(-107) . "\x0A" . str_repeat(self::n(1) . self::n(2), times: 4) . "\x12\x13\xFF\x80",
            0,
        );

        self::assertSame(
            bin2hex(self::n(-107) . "\x0A" . str_repeat(self::n(1) . self::n(2), times: 4) . "\x12\x13\xFF\x80\x0E"),
            bin2hex($glyph),
        );
    }

    #[Test]
    public function subroutinesAreConvertedAndEndWithReturn(): void
    {
        $subr      = self::n(1) . self::n(2) . self::n(3) . self::n(1) . "\x10\x15";
        $converter = new Cff2CharStrings([$subr], [[]], [1], [0]);
        $glyph     = $converter->glyph(self::n(-107) . "\x1D", 0);

        self::assertSame(
            [bin2hex(self::n(-107) . "\x1D\x0E"), bin2hex(self::n(1) . self::n(2) . "\x15\x0B")],
            [bin2hex($glyph), bin2hex($converter->globalSubrs()[0])],
        );
    }
}
