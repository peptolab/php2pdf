<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\Bidi;

use Php2Pdf\Font\Bidi\Bidi;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class BidiTest extends TestCase
{
    /** @return array<string, array{0: int, 1: bool}> */
    public static function directionProvider(): array
    {
        return [
            'Latin'  => [0x0041, false],
            'Hebrew' => [0x05D0, true],
            'Arabic' => [0x0628, true],
            'digit'  => [0x0031, false],
        ];
    }

    #[Test]
    public function anOpeningParenthesisMirrorsToAClosingOne(): void
    {
        self::assertSame(0x0029, Bidi::mirror(0x0028));
    }

    #[Test]
    #[DataProvider('directionProvider')]
    public function onlyHebrewAndArabicLettersAreRightToLeft(int $codePoint, bool $expected): void
    {
        self::assertSame($expected, Bidi::isRightToLeft($codePoint));
    }
}
