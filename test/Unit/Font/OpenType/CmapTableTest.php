<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\OpenType;

use Php2Pdf\Core\BinaryReader;
use Php2Pdf\Font\OpenType\CmapTable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function pack;

#[Group('unit')]
final class CmapTableTest extends TestCase
{
    private static function cmap(int $platform, int $encoding, string $subtable): BinaryReader
    {
        return new BinaryReader(pack('nnnnN', 0, 1, $platform, $encoding, 12) . $subtable);
    }

    #[Test]
    public function aCmapWithoutAUnicodeSubtableMapsNothing(): void
    {
        $subtable = pack('nnnnnnn', 4, 24, 0, 2, 0, 0, 0) . pack('nnnnn', 0x41, 0, 0x41, 1, 0);

        self::assertSame(
            [],
            CmapTable::parse(self::cmap(
                platform: 1,
                encoding: 0,
                subtable: $subtable,
            )),
        );
    }

    #[Test]
    public function aFormat12GroupSpanningMoreThanUnicodeIsSkipped(): void
    {
        $subtable = pack('nnNNN', 12, 0, 40, 0, 2) . pack('NNN', 0, 0x20_0000, 1) . pack('NNN', 0x41, 0x42, 5);

        self::assertSame(
            [0x41 => 5, 0x42 => 6],
            CmapTable::parse(self::cmap(
                platform: 3,
                encoding: 10,
                subtable: $subtable,
            )),
        );
    }

    #[Test]
    public function aFormat4RangeOffsetPastTheTableLeavesItsCodesUnmapped(): void
    {
        $subtable =
            pack('nnnnnnn', 4, 32, 0, 4, 0, 0, 0)
            . pack('nnn', 0x41, 0x42, 0)
            . pack('nn', 0x41, 0x42)
            . pack('nn', 0, 1)
            . pack('nn', 0x100, 0);

        self::assertSame(
            [0x42 => 0x43],
            CmapTable::parse(self::cmap(
                platform: 3,
                encoding: 1,
                subtable: $subtable,
            )),
        );
    }
}
