<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\OpenType\Cff;

use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\OpenType\Cff\Dict;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function ord;
use function str_repeat;
use function strlen;

#[Group('unit')]
final class DictTest extends TestCase
{
    /** @return array<string, array{0: int, 1: int}> */
    public static function integerProvider(): array
    {
        return [
            'zero'                   => [0, 1],
            'largest one-byte'       => [107, 1],
            'smallest one-byte'      => [-107, 1],
            'smallest positive pair' => [108, 2],
            'largest positive pair'  => [1131, 2],
            'smallest negative pair' => [-108, 2],
            'largest negative pair'  => [-1131, 2],
            'positive shortint'      => [1132, 3],
            'negative shortint'      => [-1132, 3],
            'largest shortint'       => [32_767, 3],
            'smallest shortint'      => [-32_768, 3],
            'positive longint'       => [32_768, 5],
            'negative longint'       => [-32_769, 5],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function malformedProvider(): array
    {
        return [
            'reserved byte'   => ["\x16\x11", 'Invalid CFF DICT byte 22'],
            'reserved nibble' => ["\x1E\xDF\x11", 'Reserved nibble'],
            'not a number'    => ["\x1E\xAF\x11", 'Invalid CFF real number'],
        ];
    }

    /** @return array<string, array{0: non-negative-int, 1: int}> */
    public static function offsetSizeProvider(): array
    {
        return [
            'one byte'    => [200, 1],
            'two bytes'   => [300, 2],
            'three bytes' => [70_000, 3],
        ];
    }

    /** @return array<string, array{0: string, 1: float}> */
    public static function realProvider(): array
    {
        return [
            'fraction'          => ["\x1E\x0A\x00\x1F", 0.001],
            'negative'          => ["\x1E\xE2\xA2\x5F", -2.25],
            'negative exponent' => ["\x1E\x1C\x3F", 0.001],
            'positive exponent' => ["\x1E\x1B\x2F", 100.0],
        ];
    }

    #[Test]
    public function anEmptyIndexIsTwoZeroBytes(): void
    {
        self::assertSame("\0\0", Dict::index([]));
    }

    #[Test]
    public function encodesEscapedOperators(): void
    {
        self::assertSame("\x8C\x11\x8B\x0C\x07", Dict::encode([17 => "\x8C", 1207 => "\x8B"]));
    }

    #[Test]
    public function fixedWidthIntegersRoundTrip(): void
    {
        $entry = Dict::parse(Dict::int32(123_456) . "\x11")[17] ?? null;

        self::assertSame([123_456], $entry?->values);
    }

    /** @param non-negative-int $length */
    #[Test]
    #[DataProvider('offsetSizeProvider')]
    public function indexOffsetsUseTheSmallestSizeThatFits(int $length, int $size): void
    {
        $index = Dict::index([str_repeat('x', $length)]);

        self::assertSame([$size, 3 + (2 * $size) + $length], [ord($index[2]), strlen($index)]);
    }

    #[Test]
    #[DataProvider('integerProvider')]
    public function integersRoundTripInTheirShortestForm(int $value, int $length): void
    {
        $encoded = Dict::int($value);
        $entry   = Dict::parse("{$encoded}\x11")[17] ?? null;

        self::assertSame([$length, [$value]], [strlen($encoded), $entry?->values]);
    }

    #[Test]
    public function keepsOperandsAsWrittenAndKeysEscapedOperators(): void
    {
        $operands = "\x1E\x0A\x00\x1F\x8B\x8B\x1E\x0A\x00\x1F\x8B\x8B";
        $dict     = Dict::parse("{$operands}\x0C\x07\x8C\x11");

        self::assertSame([$operands, "\x8C"], [($dict[1207] ?? null)?->operands, ($dict[17] ?? null)?->operands]);
    }

    #[Test]
    #[DataProvider('realProvider')]
    public function parsesRealNumbers(string $encoded, float $value): void
    {
        self::assertEqualsWithDelta($value, Dict::parse("{$encoded}\x11")[17]?->values[0] ?? null, 1.0e-12);
    }

    #[Test]
    #[DataProvider('malformedProvider')]
    public function rejectsMalformedData(string $data, string $message): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage($message);
        Dict::parse($data);
    }

    #[Test]
    public function veryLargeIndexesUseFourByteOffsets(): void
    {
        self::assertSame(4, ord(Dict::index([str_repeat('x', times: 0x100_0000)])[2]));
    }
}
