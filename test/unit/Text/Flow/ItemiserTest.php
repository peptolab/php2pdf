<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Text\Flow;

use PhpPdf\Text\Flow\Item;
use PhpPdf\Text\Flow\Itemiser;
use PhpPdf\Text\Flow\ItemKind;
use PhpPdfTest\Support\KerningFixtureTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_map;
use function array_sum;
use function array_values;
use function count;

#[Group('unit')]
final class ItemiserTest extends TestCase
{
    use KerningFixtureTrait;

    #[Test]
    public function aPenaltyCarriesTheKerningChangeForItsHyphen(): void
    {
        $penalty = array_values(array_filter(
            $this->itemise('TrueType'),
            static fn(Item $item): bool => ItemKind::Penalty === $item->kind,
        ))[0];
        $hyphen = Itemiser::box('-', $this->style);

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

        self::assertSame([1, -20.0], [count($glue), $glue[0]->run?->glyphs[0]?->kerning]);
    }

    #[Test]
    public function aWordKernsTowardsTheSpaceAfterIt(): void
    {
        $boxes = $this->boxes('AT Teams');

        self::assertSame(-19.0, $boxes[0]->run?->glyphs[1]?->kerning);
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
    public function softHyphensSplitWordsWithoutBreakingTheirKerning(): void
    {
        $boxes = $this->boxes("True\u{00AD}Type");

        self::assertSame(['True', 'Type', -50.0], [
            $boxes[0]->text,
            $boxes[1]->text,
            $boxes[0]->run?->glyphs[3]?->kerning,
        ]);
    }

    #[Test]
    public function syllablesKernAcrossTheHyphenationPoint(): void
    {
        $boxes = $this->boxes('TrueType');

        self::assertSame(['True', 'Type', -50.0], [
            $boxes[0]->text,
            $boxes[1]->text,
            $boxes[0]->run?->glyphs[3]?->kerning,
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

    protected function setUp(): void
    {
        $this->setUpKerning();
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
