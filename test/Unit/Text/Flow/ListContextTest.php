<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text\Flow;

use Php2Pdf\Text\Flow\ListContext;
use Php2Pdf\Text\Flow\ListPosition;
use Php2Pdf\Text\Style\ListMarker;
use Php2Pdf\Text\Style\TextStyle;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** The lists open while a flow's paragraphs are given, and where each paragraph sits in them (#35). */
#[Group('unit')]
final class ListContextTest extends TestCase
{
    /** @return array{0: list<array{0: int, 1: int, 2: string}>, 1: ?string}|null */
    private static function shape(?ListPosition $position): ?array
    {
        if (null === $position) {
            return null;
        }
        $path = [];
        foreach ($position->path as [$list, $item, $marker]) {
            $path[] = [$list, $item, $marker->value];
        }

        return [$path, $position->label];
    }

    #[Test]
    public function aListOpenedInAnItemIsNestedInIt(): void
    {
        $lists = new ListContext();
        $lists->open(null);
        $lists->item();
        $lists->position();
        $lists->open(null);
        $lists->item();

        self::assertSame([[[0, 1, 'disc'], [2, 3, 'circle']], '◦'], self::shape($lists->position()));
    }

    #[Test]
    public function anItemOutsideAListIsNoItem(): void
    {
        $lists = new ListContext();
        $lists->closeItem();
        $lists->close();

        self::assertSame([null, false, null], [$lists->item(), $lists->isInItem(), $lists->position()]);
    }

    #[Test]
    public function anItemsFirstParagraphTakesItsLabelAndTheOthersNone(): void
    {
        $lists = new ListContext();
        $lists->open(ListMarker::Decimal, start: 3);
        $lists->item();

        self::assertSame(
            [[[[0, 1, '1']], '3.'], [[[0, 1, '1']], null]],
            [self::shape($lists->position()), self::shape($lists->position())],
        );
    }

    #[Test]
    public function closingANestedListReturnsToTheItemItIsIn(): void
    {
        $lists = new ListContext();
        $lists->open(null);
        $lists->item();
        $lists->open(ListMarker::Decimal);
        $lists->item();
        $lists->closeItem();
        $lists->close();

        self::assertSame([[[0, 1, 'disc']], null], self::shape($lists->position()));
    }

    #[Test]
    public function itemsTakeTheirListsStyle(): void
    {
        $style = new TextStyle(size: 9.0);
        $lists = new ListContext();
        $lists->open(null, style: $style);

        self::assertSame($style, $lists->item());
    }

    #[Test]
    public function outsideAnItemAParagraphIsInNoList(): void
    {
        $lists = new ListContext();
        $lists->open(null);
        $lists->item();
        $lists->closeItem();

        self::assertSame([null, false], [$lists->position(), $lists->isInItem()]);
    }
}
