<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\OpenType\Cff;

use Php2Pdf\Exception\ParseException;
use Php2Pdf\Font\OpenType\Cff\Cff2Reader;
use Php2Pdf\Font\OpenType\Cff\Dict;
use Php2PdfTest\TestAsset\Font\Cff2Builder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_keys;
use function pack;

/**
 * CFF2 tables are read as their default instance (#39). Private DICT entries here use operator
 * 10 (StdHW); 22 is vsindex and 23 blend.
 */
#[Group('unit')]
final class Cff2ReaderTest extends TestCase
{
    private const string BLEND   = "\x17";
    private const string STD_HW  = "\x0A";
    private const string VSINDEX = "\x16";

    /** @return array<string, array{0: string}> */
    public static function fdSelectProvider(): array
    {
        return [
            'format 0' => ["\x00\x00\x01\x01"],
            'format 3' => ["\x03" . pack('n', 2) . pack('nC', 0, 0) . pack('nC', 1, 1) . pack('n', 3)],
            'format 4' => ["\x04" . pack('N', 2) . pack('Nn', 0, 0) . pack('Nn', 1, 1) . pack('N', 3)],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function shortBlendProvider(): array
    {
        return [
            'fewer operands than the regions need' => [Dict::int(5) . Dict::int(1) . self::BLEND . self::STD_HW],
            'a negative count'                     => [Dict::int(-1) . self::BLEND . self::STD_HW],
        ];
    }

    /** 50 blended with deltas 5 and -5 over two regions. */
    private static function blend(): string
    {
        return Dict::int(50) . Dict::int(5) . Dict::int(-5) . Dict::int(1) . self::BLEND . self::STD_HW;
    }

    #[Test]
    public function aBlendInAPrivateDictKeepsItsDefault(): void
    {
        [, , , $fonts] = (new Cff2Reader(Cff2Builder::table([''], [self::blend()], regionCounts: [2])))->read();

        self::assertSame([50], ($fonts[0]['private'][10] ?? null)?->values);
    }

    #[Test]
    #[DataProvider('shortBlendProvider')]
    public function aBlendWithTooFewOperandsIsRejected(string $private): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('CFF2 DICT blend has too few operands.');
        (new Cff2Reader(Cff2Builder::table([''], [$private], regionCounts: [2])))->read();
    }

    #[Test]
    public function anotherVersionIsRejected(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('CFF2 version 1 is not supported.');
        new Cff2Reader(Cff2Builder::table([''], version: 1));
    }

    #[Test]
    public function anUnsupportedFdSelectFormatIsRejected(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('FDSelect format 1 is not supported.');
        (new Cff2Reader(Cff2Builder::table([''], fdSelect: "\x01\x00\x00")))->read();
    }

    #[Test]
    public function aPrivateDictVsindexChoosesTheRegionsItsBlendsUse(): void
    {
        $private = Dict::int(1) . self::VSINDEX . self::blend();
        [, , , $fonts] = (new Cff2Reader(Cff2Builder::table([''], [$private], regionCounts: [1, 2])))->read();
        $entries = $fonts[0]['private'] ?? [];

        self::assertSame([[10], [50]], [array_keys($entries), ($entries[10] ?? null)?->values]);
    }

    #[Test]
    #[DataProvider('fdSelectProvider')]
    public function readsFdSelect(string $fdSelect): void
    {
        $reader = new Cff2Reader(Cff2Builder::table(['', '', ''], ['', ''], $fdSelect));

        self::assertSame([0, 1, 1], $reader->read()[4]);
    }
}
