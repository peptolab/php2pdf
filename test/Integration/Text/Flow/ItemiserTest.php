<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text\Flow;

use Override;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Font\Font;
use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Text\Flow\Item;
use Php2Pdf\Text\Flow\ItemFactory;
use Php2Pdf\Text\Flow\Itemiser;
use Php2Pdf\Text\Flow\ItemKind;
use Php2Pdf\Text\Flow\StyleResolver;
use Php2Pdf\Text\Hyphenation\Hyphenator;
use Php2Pdf\Text\Style\TextStyle;
use Php2PdfTest\TestAsset\PdfTools;
use Php2PdfTest\Trait\KerningFixtureTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_sum;
use function array_values;
use function count;
use function implode;
use function mb_chr;

#[Group('integration')]
final class ItemiserTest extends TestCase
{
    use KerningFixtureTrait;

    /** @return array<string, array{0: string, 1: list<string>}> */
    public static function breakOpportunityProvider(): array
    {
        return [
            'hard hyphen'           => ['well-known', ['well-', '|', 'known']],
            'hyphen before a digit' => ['pages 10-12', ['pages', ' ', '10-12']],
            'unicode hyphen'        => ["co\u{2010}op", ["co\u{2010}", '|', 'op']],
            'en dash'               => ["1990s\u{2013}now", ["1990s\u{2013}", '|', 'now']],
            'em dash on both sides' => ["yes\u{2014}no", ['yes', '|', "\u{2014}", '|', 'no']],
            'slash'                 => ['and/or', ['and/', '|', 'or']],
            'thin space'            => ["10\u{2009}000", ['10', "\u{2009}", '|', '000']],
            'no-break space'        => ["10\u{00A0}000", ["10\u{00A0}000"]],
            'narrow no-break space' => ["10\u{202F}000", ["10\u{202F}000"]],
        ];
    }

    /**
     * The characters the font has among letters and symbols that are left to right or neutral and
     * break nowhere: Latin, IPA, Greek and Cyrillic letters, symbols, arrows, box drawing and
     * Braille.
     *
     * @return list<string>
     */
    private static function characters(Font $font): array
    {
        $characters = [];
        foreach ([
            [0x0100, 0x02AF],
            [0x0391, 0x03C9],
            [0x0400, 0x0481],
            [0x048A, 0x04FF],
            [0x1E00, 0x1EFF],
            [0x2100, 0x23FF],
            [0x2500, 0x27BF],
            [0x2800, 0x28FF],
        ] as [$first, $last]) {
            for ($codePoint = $first; $codePoint <= $last; ++$codePoint) {
                if (! $font->hasGlyph($codePoint)) {
                    continue;
                }

                $characters[] = mb_chr($codePoint, encoding: 'UTF-8');
            }
        }

        return $characters;
    }

    /**
     * The items with every glyph's cluster set to 0.
     *
     * @param list<Item> $items
     *
     * @return list<Item>
     */
    private static function withoutClusters(array $items): array
    {
        $clear = static fn(?GlyphRun $run): ?GlyphRun => null === $run
            ? null
            : new GlyphRun(array_map(
                static fn(Glyph $glyph): Glyph => $glyph->withCluster(0),
                $run->glyphs,
            ));

        return array_map(static fn(Item $item): Item => new Item(
            $item->kind,
            $item->style,
            $item->text,
            $clear($item->run),
            $item->width,
            $clear($item->hyphenRun),
            $item->hyphenWidth,
            $item->hyphenKerning,
            $item->inline,
            $item->level,
        ), $items);
    }

    #[Test]
    public function aFixedSpaceKeepsItsOwnWidth(): void
    {
        $thin =
            array_values(array_filter(
                $this->itemise("10\u{2009}000"),
                static fn(Item $item): bool => "\u{2009}" === $item->text,
            ))[0] ?? null;
        self::assertNotNull($thin);

        self::assertSame($this->serif->width("\u{2009}", 10.0), $thin->width);
    }

    #[Test]
    public function aPenaltyCarriesTheKerningChangeForItsHyphen(): void
    {
        $penalty =
            array_values(array_filter(
                $this->itemise('TrueType'),
                static fn(Item $item): bool => ItemKind::Penalty === $item->kind,
            ))[0] ?? null;
        self::assertNotNull($penalty);
        $hyphen = ItemFactory::box('-', $this->style);

        self::assertSame(
            [60.0, $hyphen->width + 0.6],
            [$penalty->hyphenKerning, $penalty->hyphenWidth],
            'e→T is −50 and e→hyphen +10, so the hyphen pushes e out by 60.',
        );
    }

    #[Test]
    public function aRunOfSpacesIsOneGlueKernedTowardsTheNextWord(): void
    {
        $glue = array_values(array_filter(
            $this->itemise('so   Teams'),
            static fn(Item $item): bool => ItemKind::Glue === $item->kind,
        ));

        self::assertSame([1, -20.0], [count($glue), $glue[0]->run?->glyphs[0]->kerning ?? null]);
    }

    #[Test]
    public function aWordKernsTowardsTheSpaceAfterIt(): void
    {
        $boxes = $this->boxes('AT Teams');

        self::assertSame(-19.0, $boxes[0]->run?->glyphs[1]->kerning ?? null);
    }

    /** @param list<string> $expected boxes by text, glue as a space, plain breaks as | */
    #[Test]
    #[DataProvider('breakOpportunityProvider')]
    public function breaksAfterHyphensDashesSlashesAndFixedSpaces(string $text, array $expected): void
    {
        self::assertSame($expected, array_map(
            static fn(Item $item): string => match ($item->kind) {
                ItemKind::Glue    => ' ',
                ItemKind::Penalty => null === $item->hyphenRun ? '|' : '-',
                default           => $item->text,
            },
            $this->itemise($text),
        ));
    }

    #[Test]
    public function glyphsLetGoOnceTooManyAreKeptAreMadeAgainAlike(): void
    {
        $doc      = new Document(new MemoryOutput());
        $resolver = new StyleResolver($doc->text());
        $itemiser = new Itemiser($this->hyphenators);
        // Each character a word of its own in three fonts: more distinct glyphs than are kept.
        foreach (['DejaVuSans.ttf', 'DejaVuSans-Bold.ttf', 'SourceSerif4-Regular.ttf'] as $file) {
            $font  = $doc->fonts()->load(PdfTools::assetPath("fonts/{$file}"));
            $style = $resolver->resolve(null, new TextStyle(
                font: $font,
                size: 10.0,
            ));
            $itemiser->itemise([[implode(' ', self::characters($font)), $style]]);
        }
        $text = 'w1 office affine TrueType fit, w1 office';

        self::assertEquals(
            self::withoutClusters((new Itemiser($this->hyphenators))->itemise([[$text, $this->style]])),
            self::withoutClusters($itemiser->itemise([[$text, $this->style]])),
        );
    }

    #[Test]
    public function identicalItemsAreSharedAndItemsThatDifferAreNot(): void
    {
        $items = $this->itemise('a a a so so Teams');

        self::assertSame(
            ['a', ' ', 'a', ' ', 'a', ' ', 'so', ' ', 'so', ' ', 'Teams'],
            array_map(static fn(Item $item): string => $item->text, $items),
        );
        self::assertSame($items[1] ?? null, $items[3] ?? null, 'Word spaces kerned alike are one item.');
        self::assertSame($items[0] ?? null, $items[2] ?? null, 'Words shaped alike are one item.');
        self::assertSame($items[6] ?? null, $items[8] ?? null);
        self::assertNotSame($items[7] ?? null, $items[9] ?? null, 'The space before Teams kerns towards it.');
        self::assertSame(
            [0.0, -20.0],
            [
                (($items[7] ?? null)?->run?->glyphs[0] ?? null)?->kerning,
                (($items[9] ?? null)?->run?->glyphs[0] ?? null)?->kerning,
            ],
        );
        self::assertEquals(
            $this->itemise('a '),
            [$items[4] ?? null, $items[5] ?? null],
            'Sharing leaves every item as it was.',
        );
    }

    #[Test]
    public function itemsKeepTheKerningOfTheWholeText(): void
    {
        $text  = 'Precision placement. TrueType and OpenType Templates';
        $items = $this->itemise($text);
        $width = array_sum(array_map(static fn(Item $item): float => $item->width, $items));

        self::assertEqualsWithDelta($this->serif->width($text, 10.0), $width, 1.0e-9);
    }

    #[Test]
    public function itemsLetGoOnceTooManyAreKeptAreMadeAgainAlike(): void
    {
        $itemiser = new Itemiser($this->hyphenators);
        $words    = [];
        for ($n = 0; $n < 5000; ++$n) {
            $words[] = "w{$n}";
        }
        $before = $itemiser->itemise([[implode(' ', $words), $this->style]]);
        $text   = 'w1 office affine TrueType fit, w1 office';

        $after = $itemiser->itemise([[$text, $this->style]]);

        self::assertNotSame($before[2] ?? null, $after[0] ?? null, 'The items kept were let go.');
        self::assertSame('w1', ($after[0] ?? null)?->text);
        self::assertEquals(
            self::withoutClusters((new Itemiser($this->hyphenators))->itemise([[$text, $this->style]])),
            self::withoutClusters($after),
            'Items made again are alike in all but the clusters of their glyphs, which nothing reads.',
        );
        self::assertSame($after[0] ?? null, $after[12] ?? null, 'Items made after are shared again.');
    }

    #[Test]
    public function punctuationAroundAWordDoesNotStopHyphenation(): void
    {
        $items = (new Itemiser(['en' => new Hyphenator([], ['ty-pog-ra-phy'])]))->itemise([[
            '(typography,',
            $this->style,
        ]]);

        self::assertSame(
            ['(ty', '-', 'pog', '-', 'ra', '-', 'phy,'],
            array_map(
                static fn(Item $item): string => ItemKind::Penalty === $item->kind ? '-' : $item->text,
                $items,
            ),
        );
    }

    #[Test]
    public function softHyphensSplitWordsWithoutBreakingTheirKerning(): void
    {
        $boxes = $this->boxes("True\u{00AD}Type");

        $true = $boxes[0] ?? null;
        $type = $boxes[1] ?? null;
        self::assertNotNull($true);
        self::assertNotNull($type);

        self::assertSame(['True', 'Type', -50.0], [
            $true->text,
            $type->text,
            $true->run?->glyphs[3]->kerning ?? null,
        ]);
    }

    #[Test]
    public function syllablesKernAcrossTheHyphenationPoint(): void
    {
        $boxes = $this->boxes('TrueType');

        $true = $boxes[0] ?? null;
        $type = $boxes[1] ?? null;
        self::assertNotNull($true);
        self::assertNotNull($type);

        self::assertSame(['True', 'Type', -50.0], [
            $true->text,
            $type->text,
            $true->run?->glyphs[3]->kerning ?? null,
        ]);
    }

    #[Test]
    public function tabsAndLineBreaksEndTheKernedRun(): void
    {
        $kinds = array_map(static fn(Item $item): ItemKind => $item->kind, $this->itemise("A\tT\nT"));

        self::assertSame(
            [ItemKind::Box, ItemKind::Tab, ItemKind::Box, ItemKind::LineBreak, ItemKind::Box],
            $kinds,
        );
    }

    #[Test]
    public function wordsLetGoOnceTooManyAreRememberedAreCutAgainAlike(): void
    {
        $itemiser = new Itemiser($this->hyphenators);
        $words    = [];
        for ($n = 0; $n < 9000; ++$n) {
            $words[] = "w{$n}";
        }
        $itemiser->itemise([[implode(' ', $words), $this->style]]);
        $text = 'office affine TrueType fit-out, w1/w2 office';

        self::assertEquals(
            self::withoutClusters((new Itemiser($this->hyphenators))->itemise([[$text, $this->style]])),
            self::withoutClusters($itemiser->itemise([[$text, $this->style]])),
        );
    }

    #[Test]
    public function wordsWithLigaturesAreShared(): void
    {
        $items = $this->itemise('office office office');

        self::assertNotSame([], ($items[0] ?? null)?->run?->glyphs[1]->components ?? [], 'The word has a ligature.');
        self::assertSame($items[0] ?? null, $items[2] ?? null);
    }

    #[Override]
    protected function setUp(): void
    {
        [$this->serif, $this->style, $this->hyphenators] = self::kerning();
    }

    /** @return list<Item> */
    private function boxes(string $text): array
    {
        return array_values(array_filter(
            $this->itemise($text),
            static fn(Item $item): bool => ItemKind::Box === $item->kind,
        ));
    }

    /** @return list<Item> */
    private function itemise(string $text): array
    {
        return (new Itemiser($this->hyphenators))->itemise([[$text, $this->style]]);
    }
}
