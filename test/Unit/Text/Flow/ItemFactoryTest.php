<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text\Flow;

use Php2Pdf\Font\FontInterface;
use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Font\TextDirection;
use Php2Pdf\Font\TextOptions;
use Php2Pdf\Text\Flow\ItemFactory;
use Php2Pdf\Text\Flow\ItemKind;
use Php2Pdf\Text\Style\TextStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The stub fonts shape right-to-left text as glyph 2 and other text as glyph 1. */
#[Group('unit')]
final class ItemFactoryTest extends TestCase
{
    /** @return array<string, array{0: int, 1: int}> */
    public static function levelProvider(): array
    {
        return [
            'an even level shapes in the style direction' => [2, 1],
            'an odd level shapes right to left'           => [1, 2],
        ];
    }

    #[Test]
    #[DataProvider('levelProvider')]
    public function aBoxIsShapedInTheDirectionOfItsLevel(int $level, int $glyph): void
    {
        $font = $this->createStub(FontInterface::class);
        $font->method('shape')
            ->willReturnCallback(static fn(string $_text, ?TextOptions $options): GlyphRun => new GlyphRun([
                new Glyph(TextDirection::RightToLeft === $options?->direction ? 2 : 1, 0x61, 500),
            ]));

        self::assertSame(
            $glyph,
            (
                ItemFactory::box(
                    'a',
                    new TextStyle(
                        font: $font,
                        size: 10.0,
                    ),
                    $level,
                )->run?->glyphs[0] ?? null
            )
                ?->id,
        );
    }

    #[Test]
    public function glueIsAShapedWordSpace(): void
    {
        $font = $this->createStub(FontInterface::class);
        $font->method('shape')->willReturn(new GlyphRun([new Glyph(3, 0x20, 250)]));
        $font->method('width')->willReturn(2.5);
        $glue = ItemFactory::glue(new TextStyle(
            font: $font,
            size: 10.0,
        ));

        self::assertSame([ItemKind::Glue, ' ', 2.5], [$glue->kind, $glue->text, $glue->width]);
    }
}
