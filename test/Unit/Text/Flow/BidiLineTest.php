<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text\Flow;

use Php2Pdf\Text\Flow\BidiLine;
use Php2Pdf\Text\Flow\Item;
use Php2Pdf\Text\Flow\ItemKind;
use Php2Pdf\Text\Style\TextStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;

/** Rule L1: tabs, and word spaces before them or at the end of a line, take the paragraph level. */
#[Group('unit')]
final class BidiLineTest extends TestCase
{
    /** @return array<string, array{0: list<array{0: ItemKind, 1: string}>, 1: list<string>}> */
    public static function separatorProvider(): array
    {
        return [
            'a tab between right-to-left words'   => [
                [[ItemKind::Box, 'a'], [ItemKind::Tab, 'T'], [ItemKind::Box, 'b']],
                ['a', 'T', 'b'],
            ],
            'a word space before a tab'           => [
                [[ItemKind::Box, 'a'], [ItemKind::Glue, '_'], [ItemKind::Tab, 'T'], [ItemKind::Box, 'b']],
                ['a', '_', 'T', 'b'],
            ],
            'a word space at the end of the line' => [
                [[ItemKind::Box, 'a'], [ItemKind::Glue, '_'], [ItemKind::Box, 'b'], [ItemKind::Glue, '_']],
                ['b', '_', 'a', '_'],
            ],
        ];
    }

    /**
     * @param list<array{0: ItemKind, 1: string}> $logical
     * @param list<string> $visual
     */
    #[Test]
    #[DataProvider('separatorProvider')]
    public function separatorsStayAtTheLeftToRightParagraphLevel(array $logical, array $visual): void
    {
        $style = new TextStyle();
        $items = array_map(
            /** @param array{0: ItemKind, 1: string} $spec */
            static fn(array $spec): Item => new Item($spec[0], $style, $spec[1], level: 1),
            $logical,
        );

        self::assertSame(
            $visual,
            array_map(static fn(Item $item): string => $item->text, BidiLine::visual($items, paragraphLevel: 0)),
        );
    }
}
