<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Font\FontInterface;
use Php2Pdf\Font\Glyph;
use Php2Pdf\Text\Flow\Item;
use Php2Pdf\Text\Flow\Itemiser;
use Php2Pdf\Text\Flow\ItemKind;
use Php2Pdf\Text\Flow\StyleResolver;
use Php2Pdf\Text\Style\TextStyle;
use Php2PdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * Glyphs know which characters of the text they stand for, so text cut into words keeps each
 * word's glyphs when normalisation changes the number of characters: "cafe" with a combining
 * acute is shaped with the font's "é", one glyph for two characters.
 */
#[Group('integration')]
final class ShapingClusterTest extends TestCase
{
    private const string TEXT = "cafe\u{0301} x";

    private Document $document;
    private FontInterface $font;

    protected function setUp(): void
    {
        $this->document = new Document(new MemoryOutput());
        $this->font     = $this->document->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
    }

    #[Test]
    public function eachGlyphKnowsTheCharacterItsClusterStartsAt(): void
    {
        self::assertSame(
            [0, 1, 2, 3, 5, 6],
            array_map(static fn(Glyph $glyph): int => $glyph->cluster, $this->font->shape(self::TEXT)->glyphs),
        );
    }

    #[Test]
    public function wordsKeepTheirGlyphsWhenACombiningMarkIsComposed(): void
    {
        $style = (new StyleResolver($this->document))->resolve(null, new TextStyle(font: $this->font, size: 10.0));
        $items = (new Itemiser())->itemise([[self::TEXT, $style]]);

        self::assertSame(
            [['Box', "cafe\u{0301}", 4], ['Glue', ' ', 1], ['Box', 'x', 1]],
            array_map(
                static fn(Item $item): array => [$item->kind->name, $item->text, $item->run?->count()],
                $items,
            ),
        );
    }
}
