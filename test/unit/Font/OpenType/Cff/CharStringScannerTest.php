<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Font\OpenType\Cff;

use PhpPdf\Exception\PdfException;
use PhpPdf\Font\OpenType\Cff\CharStringScanner;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function pack;
use function str_repeat;

#[Group('unit')]
final class CharStringScannerTest extends TestCase
{
    /** Pushes -107, which with fewer than 1240 subrs calls subr 0. */
    private const string FIRST = "\x20";

    /** Pushes -106: subr 1. */
    private const string SECOND = "\x21";

    private const string CALLSUBR  = "\x0A";
    private const string CALLGSUBR = "\x1D";
    private const string RETURN    = "\x0B";
    private const string ENDCHAR   = "\x0E";
    private const string HSTEM     = "\x01";
    private const string HINTMASK  = "\x13";

    /** @return array<string, array{0: int, 1: int}> */
    public static function biasProvider(): array
    {
        return [
            'no subrs'         => [0, 107],
            'just under 1240'  => [1239, 107],
            'from 1240'        => [1240, 1131],
            'just under 33900' => [33_899, 1131],
            'from 33900'       => [33_900, 32_768],
        ];
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function operandProvider(): array
    {
        return [
            'one byte'          => ["\x8B", 1131],
            'shortint'          => ["\x1C\xFF\x95", 1024],
            'fixed 16.16'       => ["\xFF" . pack('N', (-107 << 16) & 0xFFFF_FFFF), 1024],
            'positive two-byte' => ["\xF7\x00", 1239],
            'negative two-byte' => ["\xFB\x00", 1023],
        ];
    }

    /** @return array<string, array{0: string, 1: list<string>, 2: string}> */
    public static function unsupportedProvider(): array
    {
        return [
            'seac'                  => ["\x8B\x8B\x8B\x8B" . self::ENDCHAR, [], 'seac'],
            'arithmetic'            => ["\x8B\x8B\x0C\x0A" . self::ENDCHAR, [], 'operator 12 10'],
            'call without a number' => [self::CALLSUBR, [self::RETURN], 'without a number'],
            'missing subroutine'    => [self::SECOND . self::CALLSUBR, [self::RETURN], 'Subroutine 1 of 1'],
            'endless recursion'     => [
                self::FIRST . self::CALLSUBR,
                [self::FIRST . self::CALLSUBR],
                'nest too deeply',
            ],
        ];
    }

    #[Test]
    public function aCharStringMayEndWithoutEndchar(): void
    {
        $scanner = new CharStringScanner([]);
        $scanner->scan(self::FIRST . self::CALLSUBR, 0, ["\x8B"]);

        self::assertSame([0 => true], $scanner->localSubrs(0));
    }

    #[Test]
    #[DataProvider('biasProvider')]
    public function biasDependsOnTheNumberOfSubroutines(int $count, int $bias): void
    {
        self::assertSame($bias, CharStringScanner::bias($count));
    }

    /** With 1240 subrs the bias is 1131, so each operand form calls a subr its value pins down. */
    #[Test]
    #[DataProvider('operandProvider')]
    public function decodesEveryOperandForm(string $operand, int $subr): void
    {
        $scanner = new CharStringScanner([]);
        $scanner->scan($operand . self::CALLSUBR . self::ENDCHAR, 0, array_fill(0, count: 1240, value: self::RETURN));

        self::assertSame([$subr => true], $scanner->localSubrs(0));
    }

    #[Test]
    public function endcharInsideASubroutineEndsTheGlyph(): void
    {
        $scanner = new CharStringScanner([]);
        $scanner->scan(self::FIRST . self::CALLSUBR . self::SECOND . self::CALLSUBR, 0, [self::ENDCHAR, self::RETURN]);

        self::assertSame([0 => true], $scanner->localSubrs(0));
    }

    #[Test]
    public function hintMaskBytesAreSkippedByTheStemCount(): void
    {
        // Three stems (two from hstem, one implicit vstem before hintmask) need one mask byte; the
        // mask byte 0x0A would read as callsubr on an empty stack if it were not skipped.
        $stems   = "\x8B\x8B\x8B\x8B" . self::HSTEM . "\x8B\x8B" . self::HINTMASK . "\x0A";
        $scanner = new CharStringScanner([]);
        $scanner->scan($stems . self::FIRST . self::CALLSUBR . self::ENDCHAR, 0, [self::RETURN]);

        self::assertSame([0 => true], $scanner->localSubrs(0));
    }

    #[Test]
    public function keepsSubroutinesApartPerFontDict(): void
    {
        $scanner = new CharStringScanner([]);
        $scanner->scan(self::SECOND . self::CALLSUBR . self::ENDCHAR, 1, [self::RETURN, self::RETURN]);

        self::assertSame([[], [1 => true]], [$scanner->localSubrs(0), $scanner->localSubrs(1)]);
    }

    #[Test]
    public function nineStemsTakeTwoMaskBytes(): void
    {
        $stems   = str_repeat("\x8B", times: 18) . self::HSTEM . self::HINTMASK . "\x0A\x0A";
        $scanner = new CharStringScanner([]);
        $scanner->scan($stems . self::FIRST . self::CALLSUBR . self::ENDCHAR, 0, [self::RETURN]);

        self::assertSame([0 => true], $scanner->localSubrs(0));
    }

    #[Test]
    public function otherEscapedOperatorsClearTheStack(): void
    {
        $flex    = "\x8B\x8B\x0C\x23";
        $scanner = new CharStringScanner([]);
        $scanner->scan($flex . self::FIRST . self::CALLSUBR . self::ENDCHAR, 0, [self::RETURN]);

        self::assertSame([0 => true], $scanner->localSubrs(0));
    }

    #[Test]
    public function recordsLocalAndGlobalSubroutinesFollowedThroughNesting(): void
    {
        $scanner = new CharStringScanner([self::SECOND . self::CALLSUBR . self::RETURN, 'unused']);
        $scanner->scan(self::FIRST . self::CALLGSUBR . self::ENDCHAR, 0, ['unused', self::RETURN]);

        self::assertSame([[0 => true], [1 => true]], [$scanner->globalSubrs(), $scanner->localSubrs(0)]);
    }

    /** @param list<string> $subrs */
    #[Test]
    #[DataProvider('unsupportedProvider')]
    public function rejectsCharStringsItCannotFollow(string $charString, array $subrs, string $message): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage($message);
        (new CharStringScanner([]))->scan($charString, 0, $subrs);
    }
}
