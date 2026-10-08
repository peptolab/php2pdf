<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\OpenType;

use Php2Pdf\Core\BinaryReader;
use Php2Pdf\Font\OpenType\NameTable;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function pack;
use function strlen;

#[Group('unit')]
final class NameTableTest extends TestCase
{
    private static function nameTable(int $platform, int $encoding, int $language, string $text): BinaryReader
    {
        return new BinaryReader(
            pack('nnn', 0, 1, 18)
                . pack('nnnnnn', $platform, $encoding, $language, NameTable::FAMILY, strlen($text), 0)
                . $text,
        );
    }

    #[Test]
    public function aMacRomanNameWithAccentsIsReadAsLatin1(): void
    {
        self::assertSame(
            [NameTable::FAMILY => "Caf\u{E9}"],
            NameTable::parse(self::nameTable(
                platform: 1,
                encoding: 0,
                language: 0,
                text: "Caf\xE9",
            )),
        );
    }

    #[Test]
    public function aNameInAnUnrankedEncodingIsIgnored(): void
    {
        self::assertSame(
            [],
            NameTable::parse(self::nameTable(
                platform: 3,
                encoding: 0,
                language: 0x409,
                text: "\0A",
            )),
        );
    }
}
