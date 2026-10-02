<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text\Flow;

use Php2Pdf\Text\Flow\Item;
use Php2Pdf\Text\Flow\ItemKind;
use Php2Pdf\Text\Flow\Paragraph;
use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\Composer;
use Php2Pdf\Text\Style\TextStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The words 9, 2, 2, 2 and 9 pt wide with 2 pt spaces: in 20 pt the paragraph composer sets
 * four words on the first line, shrinking its spaces, where the line breaker would set three.
 */
#[Group('unit')]
final class ParagraphTest extends TestCase
{
    /** @return array<string, array{?Alignment, ?Composer, ?int}> */
    public static function composerProvider(): array
    {
        return [
            'justified'                      => [Alignment::Justify, null, 7],
            'justified, line by line'        => [Alignment::Justify, Composer::SingleLine, null],
            'left aligned'                   => [Alignment::Left, null, null],
            'left aligned, by the paragraph' => [Alignment::Left, Composer::Paragraph, 7],
            'no alignment'                   => [null, null, null],
        ];
    }

    private static function box(float $width): Item
    {
        return new Item(ItemKind::Box, new TextStyle(), width: $width);
    }

    private static function paragraph(TextStyle $style): Paragraph
    {
        $glue = new Item(ItemKind::Glue, new TextStyle(), width: 2.0);

        return new Paragraph($style, [
            self::box(9.0),
            $glue,
            self::box(2.0),
            $glue,
            self::box(2.0),
            $glue,
            self::box(2.0),
            $glue,
            self::box(9.0),
        ]);
    }

    #[Test]
    public function aNewWidthPlansTheLinesAgain(): void
    {
        $paragraph = self::paragraph(new TextStyle(alignment: Alignment::Justify));
        $paragraph->plannedEnd(0, 20.0, 0);

        self::assertSame(9, $paragraph->plannedEnd(0, 40.0, 0)?->index);
    }

    #[Test]
    public function splittingAnItemPlansTheLinesAgain(): void
    {
        $paragraph = self::paragraph(new TextStyle(alignment: Alignment::Justify));
        $paragraph->plannedEnd(0, 20.0, 0);
        $paragraph->split(0, [self::box(4.0), self::box(5.0)]);

        self::assertSame(8, $paragraph->plannedEnd(0, 20.0, 0)?->index);
    }

    #[Test]
    public function textTheComposerCannotSetHasNoPlan(): void
    {
        $style     = new TextStyle(alignment: Alignment::Justify);
        $paragraph = new Paragraph($style, [self::box(5.0), new Item(ItemKind::Tab, $style), self::box(5.0)]);

        self::assertNull($paragraph->plannedEnd(0, 20.0, 0));
    }

    #[Test]
    #[DataProvider('composerProvider')]
    public function theComposerSetsJustifiedTextUnlessTheStyleSaysOtherwise(
        ?Alignment $alignment,
        ?Composer $composer,
        ?int $end,
    ): void {
        $paragraph = self::paragraph(new TextStyle(
            alignment: $alignment,
            composer: $composer,
        ));

        self::assertSame($end, $paragraph->plannedEnd(0, 20.0, 0)?->index);
    }

    #[Test]
    public function theFirstLineIndentNarrowsOnlyTheFirstLine(): void
    {
        $paragraph = self::paragraph(new TextStyle(
            alignment: Alignment::Justify,
            firstLineIndent: 5.0,
        ));

        self::assertSame(
            [3, 9],
            [$paragraph->plannedEnd(0, 20.0, 0)?->index, $paragraph->plannedEnd(4, 20.0, 1)?->index],
        );
    }

    #[Test]
    public function theIndentsNarrowTheLines(): void
    {
        $paragraph = self::paragraph(new TextStyle(
            alignment: Alignment::Justify,
            leftIndent: 6.0,
            rightIndent: 4.0,
        ));

        self::assertSame(7, $paragraph->plannedEnd(0, 30.0, 0)?->index);
    }

    #[Test]
    public function theNextLineComesFromThePlan(): void
    {
        $paragraph = self::paragraph(new TextStyle(alignment: Alignment::Justify));
        $paragraph->plannedEnd(0, 20.0, 0);

        self::assertSame(9, $paragraph->plannedEnd(8, 20.0, 1)?->index);
    }
}
