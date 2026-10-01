<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\OpenType\Cff;

use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\OpenType\Cff\CffTable;
use Php2Pdf\Font\OpenType\Cff\Dict;
use Php2PdfTest\Support\CffBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function count;
use function pack;

#[Group('unit')]
final class CffTableTest extends TestCase
{
    private const string ENDCHAR = "\x0E";
    private const string RETURN  = "\x0B";

    /** @return array<string, array{0: string}> */
    public static function fdSelectProvider(): array
    {
        return [
            'format 0' => ["\x00\x00\x01\x01"],
            'format 3' => ["\x03" . pack('n', 2) . pack('nC', 0, 0) . pack('nC', 1, 1) . pack('n', 3)],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function malformedProvider(): array
    {
        $head = "\x01\x00\x04\x04" . Dict::index(['Test']);

        return [
            'version 2'              => [CffBuilder::nameKeyed([self::ENDCHAR], version: 2), 'CFF version 2'],
            'two fonts'              => [
                CffBuilder::nameKeyed([self::ENDCHAR], names: ['A', 'B']),
                'more than one font',
            ],
            'type 1 charstrings'     => [CffBuilder::nameKeyed([self::ENDCHAR], top: [1206 => Dict::int(1)]), 'Type 2'],
            'no CharStrings'         => [$head . Dict::index(['']) . "\0\0\0\0", 'lacks operator 17'],
            'no Private'             => [CffBuilder::nameKeyed([self::ENDCHAR], private: null), 'no Private DICT'],
            'offset size 0'          => ["\x01\x00\x04\x04\x00\x01\x00", 'offset size 0'],
            'offsets out of order'   => ["\x01\x00\x04\x04\x00\x01\x01\x03\x01TT", 'not in order'],
            'FDSelect format 1'      => [CffBuilder::cidKeyed([self::ENDCHAR], "\x01", [[]]), 'format 1'],
            'FDSelect gap'           => [
                CffBuilder::cidKeyed([self::ENDCHAR, self::ENDCHAR], "\x03" . pack('nCn', 1, 1, 0) . pack('n', 2), [
                    [],
                    [],
                ]),
                'does not cover glyph 0',
            ],
            'Font DICT out of range' => [CffBuilder::cidKeyed([self::ENDCHAR], "\x00\x01", [[]]), 'Font DICT 1 of 1'],
        ];
    }

    #[Test]
    public function anEmptyPrivateDictHasNoEntries(): void
    {
        $table = CffTable::parse(CffBuilder::nameKeyed([self::ENDCHAR]));

        self::assertSame([], $table->fonts[0]['private'] ?? null);
    }

    #[Test]
    public function aPrivateDictWithoutSubrsHasNoLocalSubrs(): void
    {
        $table = CffTable::parse(CffBuilder::nameKeyed([self::ENDCHAR], private: [20 => Dict::int(500)]));

        self::assertSame([[20], []], [
            array_keys($table->fonts[0]['private'] ?? []),
            $table->fonts[0]['subrs'] ?? null,
        ]);
    }

    #[Test]
    public function readsANameKeyedFontAsOneFontDict(): void
    {
        $table = CffTable::parse(CffBuilder::nameKeyed([self::ENDCHAR, self::ENDCHAR], [], [self::RETURN]));

        self::assertSame(
            [false, 1, [0, 0], [self::RETURN]],
            [$table->cidKeyed, count($table->fonts), $table->fdSelect, $table->fonts[0]['subrs'] ?? null],
        );
    }

    #[Test]
    #[DataProvider('fdSelectProvider')]
    public function readsFdSelect(string $fdSelect): void
    {
        $table = CffTable::parse(CffBuilder::cidKeyed(
            [self::ENDCHAR, self::ENDCHAR, self::ENDCHAR],
            $fdSelect,
            [[self::RETURN], []],
        ));

        self::assertSame(
            [true, [0, 1, 1], [self::RETURN], []],
            [$table->cidKeyed, $table->fdSelect, $table->fonts[0]['subrs'] ?? null, $table->fonts[1]['subrs'] ?? null],
        );
    }

    #[Test]
    #[DataProvider('malformedProvider')]
    public function rejectsWhatItCannotSubset(string $data, string $message): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage($message);
        CffTable::parse($data);
    }
}
