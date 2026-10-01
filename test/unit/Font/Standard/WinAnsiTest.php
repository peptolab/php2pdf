<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Font\Standard;

use PhpPdf\Font\Standard\WinAnsi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function count;

#[Group('unit')]
final class WinAnsiTest extends TestCase
{
    /** @return array<string, array{0: int, 1: int|null}> */
    public static function byteProvider(): array
    {
        return [
            'space'                => [0x20, 0x20],
            'tilde'                => [0x7E, 0x7E],
            'no-break space'       => [0xA0, 0xA0],
            'y with diaeresis'     => [0xFF, 0xFF],
            'euro sign'            => [0x20AC, 0x80],
            'right single quote'   => [0x2019, 0x92],
            'Y with diaeresis'     => [0x0178, 0x9F],
            'control character'    => [0x0A, null],
            'delete'               => [0x7F, null],
            'C1 control character' => [0x81, null],
            'outside Latin'        => [0x4E2D, null],
        ];
    }

    #[Test]
    #[DataProvider('byteProvider')]
    public function mapsCodePointsToTheirByte(int $codePoint, ?int $byte): void
    {
        self::assertSame($byte, WinAnsi::byte($codePoint));
    }

    #[Test]
    public function theMapCoversPrintableAsciiLatin1AndTheWindowsExtras(): void
    {
        $map = WinAnsi::map();

        self::assertSame([95 + 96 + 27, 0x80, 0xFF], [count($map), $map[0x20AC] ?? null, $map[0xFF] ?? null]);
    }
}
