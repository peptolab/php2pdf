<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text\Flow;

use Php2Pdf\Font\FontInterface;
use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Text\Flow\Item;
use Php2Pdf\Text\Flow\Itemiser;
use Php2Pdf\Text\Flow\ItemKind;
use Php2Pdf\Text\Style\TextStyle;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

/** Word spaces as a font may shape them: moved by an offset, or with no glyph at all. */
#[Group('unit')]
final class ItemiserTest extends TestCase
{
    #[Test]
    public function aWordSpaceMovedByAnOffsetKeepsItsOffset(): void
    {
        $items = $this->itemise('a b a b', [
            new Glyph(1, 0x61, 500),
            (new Glyph(2, 0x20, 250))->withOffset(30.0, 0.0)
                ->withCluster(1),
            (new Glyph(3, 0x62, 500))->withCluster(2),
            (new Glyph(2, 0x20, 250))->withOffset(30.0, 0.0)
                ->withCluster(3),
            (new Glyph(1, 0x61, 500))->withCluster(4),
            (new Glyph(2, 0x20, 250))->withOffset(30.0, 0.0)
                ->withCluster(5),
            (new Glyph(3, 0x62, 500))->withCluster(6),
        ]);

        self::assertSame(
            [30.0, 30.0, 30.0],
            array_map(
                static fn(Item $item): ?float => ($item->run?->glyphs[0] ?? null)?->xOffset,
                $this->glue($items),
            ),
        );
    }

    #[Test]
    public function aWordSpaceTheFontShowsNoGlyphForMakesNoGlue(): void
    {
        $items = $this->itemise('a b', [new Glyph(1, 0x61, 500), (new Glyph(3, 0x62, 500))->withCluster(2)]);

        self::assertSame([], $this->glue($items));
    }

    /**
     * @param list<Item> $items
     *
     * @return list<Item>
     */
    private function glue(array $items): array
    {
        $glue = [];
        foreach ($items as $item) {
            if (ItemKind::Glue !== $item->kind) {
                continue;
            }
            $glue[] = $item;
        }

        return $glue;
    }

    /**
     * Itemises the text in a font that shapes it as the given glyphs.
     *
     * @param list<Glyph> $glyphs
     *
     * @return list<Item>
     */
    private function itemise(string $text, array $glyphs): array
    {
        $font = $this->createStub(FontInterface::class);
        $font->method('shape')->willReturn(new GlyphRun($glyphs));

        return (new Itemiser())->itemise([[
            $text,
            new TextStyle(
                font: $font,
                size: 10.0,
            ),
        ]]);
    }
}
