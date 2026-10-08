<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font;

use Php2Pdf\Font\FontStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class FontStyleTest extends TestCase
{
    /** @return array<string, array{0: FontStyle, 1: array{0: bool, 1: bool}}> */
    public static function styleProvider(): array
    {
        return [
            'regular'     => [FontStyle::Regular, [false, false]],
            'bold'        => [FontStyle::Bold, [true, false]],
            'italic'      => [FontStyle::Italic, [false, true]],
            'bold italic' => [FontStyle::BoldItalic, [true, true]],
        ];
    }

    /** @param array{0: bool, 1: bool} $expected */
    #[Test]
    #[DataProvider('styleProvider')]
    public function eachStyleKnowsWhetherItIsBoldAndItalic(FontStyle $style, array $expected): void
    {
        self::assertSame($expected, [$style->isBold(), $style->isItalic()]);
    }
}
