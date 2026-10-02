<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text\Flow;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\FontInterface;
use Php2Pdf\Font\StandardFont;
use Php2Pdf\Text\Flow\Item;
use Php2Pdf\Text\Flow\Itemiser;
use Php2Pdf\Text\Flow\ItemKind;
use Php2Pdf\Text\Flow\StyleResolver;
use Php2Pdf\Text\Style\TextStyle;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function array_values;

/** Characters a font lacks come from the first fallback font that has them (#22). */
#[Group('integration')]
final class FontFallbackTest extends TestCase
{
    private Document $doc;
    private FontInterface $dejaVu;
    private FontInterface $helvetica;
    private FontInterface $source;

    /**
     * @param list<Item> $items
     *
     * @return list<array{0: string, 1: string}>
     */
    private static function boxes(array $items): array
    {
        $boxes = [];
        foreach ($items as $item) {
            if (ItemKind::Box !== $item->kind) {
                continue;
            }
            $boxes[] = [$item->text, StyleResolver::fontOf($item->style)->name()];
        }

        return $boxes;
    }

    #[Test]
    public function aCharacterNoFontHasFollowsTheFontsPolicy(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('The standard font Helvetica cannot show "☃"');
        $this->itemise('☃', new TextStyle(
            font: $this->helvetica,
            fallback: [$this->source],
        ));
    }

    #[Test]
    public function aWordSetInTwoFontsStaysUnbroken(): void
    {
        $items = $this->itemise('x☃y', new TextStyle(
            font: $this->source,
            fallback: [$this->dejaVu],
        ));

        self::assertSame(
            [ItemKind::Box, ItemKind::Box, ItemKind::Box],
            array_map(static fn(Item $item): ItemKind => $item->kind, $items),
        );
    }

    #[Test]
    public function charactersTheFontLacksComeFromTheFallback(): void
    {
        $items = $this->itemise('Ω = 5', new TextStyle(
            font: $this->helvetica,
            fallback: [$this->dejaVu],
        ));

        self::assertSame(
            [['Ω', 'DejaVuSans'], ['=', 'Helvetica'], ['5', 'Helvetica']],
            self::boxes($items),
        );
    }

    #[Test]
    public function fallbackFontsMayBeNamed(): void
    {
        $this->doc->fonts()->addSearchPath(PdfTools::assetPath('fonts'));
        $items = $this->itemise('☃', new TextStyle(
            font: $this->source,
            fallback: ['DejaVuSans'],
        ));

        self::assertSame([['☃', 'DejaVuSans']], self::boxes($items));
    }

    #[Test]
    public function kerningStopsAtAFontChange(): void
    {
        $items = $this->itemise('T☃', new TextStyle(
            font: $this->source,
            fallback: [$this->dejaVu],
        ));

        self::assertSame(0.0, $items[0]->run?->glyphs[0]?->kerning);
    }

    #[Test]
    public function theFirstFallbackThatHasTheCharacterWins(): void
    {
        $items = $this->itemise('a☃', new TextStyle(
            font: $this->helvetica,
            fallback: [$this->source, $this->dejaVu],
        ));

        self::assertSame([['a', 'Helvetica'], ['☃', 'DejaVuSans']], self::boxes($items));
    }

    protected function setUp(): void
    {
        $this->doc       = new Document(new MemoryOutput());
        $this->dejaVu    = $this->doc->fonts()->load(PdfTools::assetPath('fonts/DejaVuSans.ttf'));
        $this->source    = $this->doc->fonts()->load(PdfTools::assetPath('fonts/SourceSans3-Regular.otf'));
        $this->helvetica = $this->doc->fonts()->standard(StandardFont::Helvetica);
    }

    /** @return list<Item> */
    private function itemise(string $text, TextStyle $style): array
    {
        return array_values((new Itemiser())->itemise([[$text, (new StyleResolver($this->doc))->resolve($style)]]));
    }
}
