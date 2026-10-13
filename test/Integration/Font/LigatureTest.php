<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font;

use Override;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Font\FontInterface;
use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\StandardFont;
use Php2Pdf\Font\TextOptions;
use Php2Pdf\Text\Flow\Item;
use Php2Pdf\Text\Flow\Itemiser;
use Php2Pdf\Text\Flow\ItemKind;
use Php2Pdf\Text\Flow\StyleResolver;
use Php2Pdf\Text\Hyphenation\Hyphenator;
use Php2Pdf\Text\Style\TextStyle;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_values;
use function preg_match;

/** Standard ligatures in shaping, copied text and line breaking (#5). */
#[Group('integration')]
final class LigatureTest extends TestCase
{
    private Document $doc;
    private MemoryOutput $out;
    private FontInterface $serif;

    /** @return array<string, array{0: TextOptions|null, 1: list<string>}> */
    public static function optionsProvider(): array
    {
        return [
            'on by default'         => [null, ['o', 'ffi', 'c', 'e']],
            'turned off'            => [new TextOptions(ligatures: false), ['o', 'f', 'f', 'i', 'c', 'e']],
            'off with tracking'     => [new TextOptions(tracking: 50.0), ['o', 'f', 'f', 'i', 'c', 'e']],
            'off with char spacing' => [new TextOptions(charSpacing: 1.0), ['o', 'f', 'f', 'i', 'c', 'e']],
        ];
    }

    /**
     * @param list<Glyph> $glyphs
     *
     * @return list<string>
     */
    private static function texts(array $glyphs): array
    {
        return array_map(static fn(Glyph $glyph): string => $glyph->text(), $glyphs);
    }

    #[Test]
    public function aHyphenationPointInsideALigatureSplitsIt(): void
    {
        $items = $this->itemise('office', new Itemiser(['en' => new Hyphenator([], ['of-fice'])]), true);

        self::assertSame([['o', 'f'], '-', ['f', 'i', 'c', 'e']], $items);
    }

    #[Test]
    public function aStyleChangeBreaksTheLigature(): void
    {
        $style = (new StyleResolver($this->doc->text()))->resolve(null, new TextStyle(
            font: $this->serif,
            size: 10.0,
        ));
        $other = $style->merge(new TextStyle(size: 11.0));
        $items = (new Itemiser())->itemise([['of', $style], ['fice', $other]]);
        $boxes = array_values(array_filter($items, static fn(Item $item): bool => ItemKind::Box === $item->kind));

        self::assertSame(
            [['o', 'f'], ['fi', 'c', 'e']],
            array_map(
                /** @return list<string> */
                static fn(Item $item): array => self::texts($item->run->glyphs ?? []),
                $boxes,
            ),
        );
    }

    #[Test]
    public function copiedTextExpandsTheLigature(): void
    {
        $this->doc->addPage(PageSize::A4)->canvas()->drawText('office', $this->serif, 12.0, 10.0, 10.0);
        $this->doc->close();
        $match = [];

        self::assertSame(1, preg_match('/<[0-9A-F]{2,6}> <006600660069>/', $this->out->contents(), $match));
    }

    #[Test]
    public function measuresWithTheLigatureGlyph(): void
    {
        $with    = $this->serif->width('office', 10.0);
        $without = $this->serif->width('office', 10.0, new TextOptions(ligatures: false));

        self::assertNotEqualsWithDelta($with, $without, 1.0e-6);
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('optionsProvider')]
    public function shapesLigaturesUnlessTheTextIsSpacedOut(?TextOptions $options, array $expected): void
    {
        self::assertSame($expected, self::texts($this->serif->shape('office', $options)->glyphs));
    }

    #[Test]
    public function standardFontsHaveNoLigatures(): void
    {
        $helvetica = $this->doc->fonts()->standard(StandardFont::Helvetica);

        self::assertSame(['o', 'f', 'f', 'i', 'c', 'e'], self::texts($helvetica->shape('office')->glyphs));
    }

    #[Override]
    protected function setUp(): void
    {
        $this->out   = new MemoryOutput();
        $this->doc   = new Document($this->out, new DocumentOptions(compress: false));
        $this->serif = $this->doc->fonts()->load(PdfTools::assetPath('fonts/SourceSerif4-Regular.ttf'));
    }

    /** @return list<list<string>|string> boxes as their glyphs' texts, penalties as "-" */
    private function itemise(string $text, Itemiser $itemiser, bool $hyphenate): array
    {
        $style = (new StyleResolver($this->doc->text()))->resolve(null, new TextStyle(
            font: $this->serif,
            size: 10.0,
            hyphenate: $hyphenate,
            language: 'en',
            minHyphenatedWordLength: 4,
        ));
        $out = [];
        foreach ($itemiser->itemise([[$text, $style]]) as $item) {
            $out[] = ItemKind::Penalty === $item->kind ? '-' : self::texts($item->run->glyphs ?? []);
        }

        return $out;
    }
}
