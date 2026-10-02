<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text\Flow;

use Php2Pdf\Text\Flow\Item;
use Php2Pdf\Text\Flow\ItemKind;
use Php2Pdf\Text\Flow\LineEnd;
use Php2Pdf\Text\Flow\Paragraph;
use Php2Pdf\Text\Flow\ParagraphComposer;
use Php2Pdf\Text\Style\TextStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

/**
 * Paragraphs of boxes and 2 pt spaces, which stretch by 0.66 pt and shrink by 0.4 pt.
 */
#[Group('unit')]
final class ParagraphComposerTest extends TestCase
{
    /** @return array<string, array{list<Item>}> */
    public static function lineByLineProvider(): array
    {
        return [
            'a tab'                    => [[self::box(5.0), new Item(ItemKind::Tab, new TextStyle()), self::box(5.0)]],
            'a word wider than a line' => [[self::box(5.0), self::glue(), self::box(21.0)]],
        ];
    }

    private static function box(float $width): Item
    {
        return new Item(ItemKind::Box, new TextStyle(), width: $width);
    }

    private static function glue(): Item
    {
        return new Item(ItemKind::Glue, new TextStyle(), width: 2.0);
    }

    /**
     * The line ends' indexes.
     *
     * @param list<LineEnd>|null $ends
     *
     * @return list<int>|null
     */
    private static function indexes(?array $ends): ?array
    {
        return null === $ends ? null : array_map(static fn(LineEnd $end): int => $end->index, $ends);
    }

    /**
     * Boxes with a space between each.
     *
     * @param list<Item> $boxes
     */
    private static function paragraph(array $boxes): Paragraph
    {
        $items = [];
        foreach ($boxes as $i => $box) {
            if ($i > 0) {
                $items[] = self::glue();
            }
            $items[] = $box;
        }

        return new Paragraph(new TextStyle(), $items);
    }

    #[Test]
    public function aLineBreaksAtAHyphenationPointToFitExactly(): void
    {
        $hyphen    = new Item(ItemKind::Penalty, new TextStyle(), hyphenWidth: 1.0);
        $paragraph = new Paragraph(new TextStyle(), [
            self::box(9.0),
            self::glue(),
            self::box(6.0),
            $hyphen,
            self::box(6.0),
        ]);
        $ends = ParagraphComposer::breaks($paragraph, 0, 18.0, 18.0);

        self::assertSame([3, $hyphen], [$ends[0]->index ?? null, $ends[0]->hyphen ?? null]);
    }

    #[Test]
    public function aLineShrinksToTakeAWordRatherThanLeaveTheLineBeforeLoose(): void
    {
        $paragraph = self::paragraph([self::box(9.0), self::box(2.0), self::box(2.0), self::box(2.0), self::box(9.0)]);

        self::assertSame(
            [7, 9],
            self::indexes(ParagraphComposer::breaks($paragraph, 0, 20.0, 20.0)),
            'Filling the first line to 17 pt stretches its two spaces by 1.5 pt each; '
                . 'taking the third word shrinks its three by 0.33 pt.',
        );
    }

    #[Test]
    public function aLineThatCannotFitWithinToleranceIsStillSet(): void
    {
        $paragraph = self::paragraph([self::box(9.0), self::box(15.0)]);

        self::assertSame([1, 3], self::indexes(ParagraphComposer::breaks($paragraph, 0, 20.0, 20.0)));
    }

    #[Test]
    public function aLooseLineIsBalancedAgainstTheNext(): void
    {
        $paragraph = self::paragraph([
            self::box(3.0),
            self::box(5.0),
            self::box(3.0),
            self::box(4.0),
            self::box(4.0),
            self::box(5.0),
        ]);

        self::assertSame([7, 11], self::indexes(ParagraphComposer::breaks($paragraph, 0, 20.0, 20.0)));
    }

    /** @param list<Item> $items */
    #[Test]
    #[DataProvider('lineByLineProvider')]
    public function textTheComposerCannotSetIsLeftToTheLineBreaker(array $items): void
    {
        self::assertNull(ParagraphComposer::breaks(new Paragraph(new TextStyle(), $items), 0, 20.0, 20.0));
    }

    #[Test]
    public function theFirstLineIsSetToItsOwnWidth(): void
    {
        $paragraph = self::paragraph([self::box(9.0), self::box(2.0), self::box(2.0), self::box(2.0), self::box(9.0)]);

        self::assertSame([3, 9], self::indexes(ParagraphComposer::breaks($paragraph, 0, 15.0, 20.0)));
    }

    #[Test]
    public function theLinesStartWhereTheyAreAsked(): void
    {
        $paragraph = self::paragraph([self::box(9.0), self::box(2.0), self::box(2.0), self::box(2.0), self::box(9.0)]);

        self::assertSame([9], self::indexes(ParagraphComposer::breaks($paragraph, 8, 20.0, 20.0)));
    }

    #[Test]
    public function theLinesStopAtTheNextForcedBreak(): void
    {
        $paragraph = new Paragraph(new TextStyle(), [
            self::box(5.0),
            self::glue(),
            self::box(5.0),
            new Item(ItemKind::LineBreak, new TextStyle()),
            self::box(5.0),
        ]);

        self::assertEquals([new LineEnd(3, null, true)], ParagraphComposer::breaks($paragraph, 0, 100.0, 100.0));
    }
}
