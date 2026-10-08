<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\OpenType;

use Php2Pdf\Core\BinaryReader;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Font\OpenType\TableDirectory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function pack;

#[Group('unit')]
final class TableDirectoryTest extends TestCase
{
    /** @return array<string, array{0: int}> */
    public static function missingFaceProvider(): array
    {
        return [
            'a negative face'        => [-1],
            'a face beyond the last' => [2],
        ];
    }

    /** A collection of two faces, each with one four-byte table: "aaaa" at 76 and "bbbb" at 80. */
    private static function collection(): BinaryReader
    {
        return new BinaryReader(
            'ttcf'
                . pack('nnN', 1, 0, 2)
                . pack('NN', 20, 48)
                . self::face('aaaa', offset: 76)
                . self::face('bbbb', offset: 80)
                . 'AAAABBBB',
        );
    }

    private static function face(string $tag, int $offset): string
    {
        return "\x00\x01\x00\x00" . pack('nnnn', 1, 16, 0, 0) . pack('a4NNN', $tag, 0, $offset, 4);
    }

    #[Test]
    public function aCollectionFaceIsReadFromItsOwnDirectory(): void
    {
        self::assertSame(
            ['bbbb' => ['offset' => 80, 'length' => 4]],
            (new TableDirectory(self::collection(), 1))->tables,
        );
    }

    #[Test]
    #[DataProvider('missingFaceProvider')]
    public function aFaceTheCollectionLacksIsRejected(int $faceIndex): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage("Font collection has 2 faces; face {$faceIndex} requested.");
        new TableDirectory(self::collection(), $faceIndex);
    }

    #[Test]
    public function aTableBeyondTheFontDataIsRejected(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('Table "head" lies outside the font data.');
        new TableDirectory(new BinaryReader(self::face('head', offset: 28)));
    }
}
