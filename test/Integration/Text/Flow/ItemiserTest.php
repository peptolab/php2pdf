<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Text\Flow;

use Override;
use Php2Pdf\Text\Flow\Item;
use Php2Pdf\Text\Flow\ItemFactory;
use Php2Pdf\Text\Flow\Itemiser;
use Php2Pdf\Text\Flow\ItemKind;
use Php2Pdf\Text\Hyphenation\Hyphenator;
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
