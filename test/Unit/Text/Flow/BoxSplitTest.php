<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text\Flow;

use Php2Pdf\Text\Flow\BoxSplit;
use Php2Pdf\Text\Flow\Item;
use Php2Pdf\Text\Flow\ItemKind;
use Php2Pdf\Text\Flow\LineEnd;
use Php2Pdf\Text\Style\TextStyle;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class BoxSplitTest extends TestCase
{
    #[Test]
    public function aHeadThatIsTheWholeBoxTakesItWhole(): void
    {
        $style = new TextStyle();
        $head  = new Item(ItemKind::Box, $style, 'W');
        $items = [new Item(ItemKind::Box, $style, 'a'), new Item(ItemKind::Box, $style, 'W')];

        self::assertSame(
            [[$items[0], $head], [1, 0]],
            BoxSplit::line($items, new LineEnd(2, null, forced: false, head: $head), offset: 1, carry: 0),
        );
    }

    #[Test]
    public function aLineEndWithoutAHeadKeepsItsItems(): void
    {
        $style = new TextStyle();
        $items = [new Item(ItemKind::Box, $style, 'a'), new Item(ItemKind::Box, $style, 'b')];

        self::assertSame(
            [$items, [2, 0]],
            BoxSplit::line($items, new LineEnd(2, null, forced: false), offset: 0, carry: 0),
        );
    }
}
