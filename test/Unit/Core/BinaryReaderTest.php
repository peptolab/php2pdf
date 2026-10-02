<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Core;

use Php2Pdf\Core\BinaryReader;
use Php2Pdf\Exception\ParseException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function pack;

#[Group('unit')]
final class BinaryReaderTest extends TestCase
{
    /** @return array<string, array{0: int, 1: int}> offset and length of reads past the data */
    public static function outsideProvider(): array
    {
        return [
            'past the end'       => [6, 4],
            'before the start'   => [-1, 2],
            'straddling the end' => [7, 2],
        ];
    }

    private static function reader(): BinaryReader
    {
        return new BinaryReader("\xFF\xFE\x00\x01\x80\x00\x00\x00", what: 'the test data');
    }

    #[Test]
    #[DataProvider('outsideProvider')]
    public function aReadOutsideTheDataSaysWhatWasCutShort(int $offset, int $length): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage("The test data is cut short: it has no {$length} bytes at offset {$offset}.");

        self::reader()->bytes($offset, $length);
    }

    #[Test]
    public function bytesAndTagsAreRead(): void
    {
        $reader = new BinaryReader('OTTO1234');

        self::assertSame(['OTTO', '12'], [$reader->tag(0), $reader->bytes(4, 2)]);
    }

    #[Test]
    public function emptyListsReadNothing(): void
    {
        self::assertSame([[], []], [self::reader()->u16List(100, 0), self::reader()->u32List(100, 0)]);
    }

    #[Test]
    public function fixedPointIsSixteenDotSixteen(): void
    {
        self::assertSame(-1.5, (new BinaryReader("\xFF\xFE\x80\x00"))->fixed(0));
    }

    #[Test]
    public function floatsAreIeeeSinglePrecision(): void
    {
        self::assertSame(0.25, (new BinaryReader(pack('G', 0.25)))->f32(0));
    }

    #[Test]
    public function integersAreBigEndian(): void
    {
        $reader = self::reader();

        self::assertSame(
            [0xFF, 0xFFFE, 0x0001_8000, 0xFFFE_0001, [0xFFFE, 0x0001], [0xFFFE_0001, 0x8000_0000]],
            [
                $reader->u8(0),
                $reader->u16(0),
                $reader->u32(2),
                $reader->u32(0),
                $reader->u16List(0, 2),
                $reader->u32List(0, 2),
            ],
        );
    }

    #[Test]
    public function signedIntegersAreTwosComplement(): void
    {
        $reader = self::reader();

        self::assertSame([-2, -131_071, -32_768], [$reader->i16(0), $reader->i32(0), $reader->i16(4)]);
    }
}
