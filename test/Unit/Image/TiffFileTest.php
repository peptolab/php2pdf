<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Image;

use Php2Pdf\Image\TiffFile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function pack;

/** Tag values are read by field type; what cannot be read is left out rather than refused. */
#[Group('unit')]
final class TiffFileTest extends TestCase
{
    /** @return array<string, array{0: int, 1: list<int>}> */
    public static function valuesProvider(): array
    {
        return [
            'bytes'                     => [1, [5, 7]],
            'undefined bytes'           => [7, [9]],
            'ascii, not a number'       => [2, []],
            'a tag that is not present' => [99, []],
        ];
    }

    /** An entry whose values (at most four bytes) sit in the entry itself. */
    private static function entry(int $tag, int $type, int $count, string $value): string
    {
        return pack('vvV', $tag, $type, $count) . $value;
    }

    /** A little-endian file with one directory, its entries 12 bytes each, and no next directory. */
    private static function file(int $count, string $entries): string
    {
        return "II*\0" . pack('V', 8) . pack('v', $count) . $entries;
    }

    #[Test]
    public function aDirectoryCutShortKeepsTheEntriesBeforeTheCut(): void
    {
        $file = new TiffFile(self::file(3, self::entry(1, 1, 2, "\x05\x07\0\0")));

        self::assertSame([5, 7], $file->values(0, 1));
    }

    /**
     * @param list<int> $expected
     */
    #[Test]
    #[DataProvider('valuesProvider')]
    public function readsNumericValuesByFieldType(int $tag, array $expected): void
    {
        $file = new TiffFile(
            self::file(
                3,
                self::entry(1, 1, 2, "\x05\x07\0\0")
                    . self::entry(2, 2, 3, "ab\0\0")
                    . self::entry(7, 7, 1, "\x09\0\0\0"),
            )
                . pack('V', 0),
        );

        self::assertSame($expected, $file->values(0, $tag));
    }
}
