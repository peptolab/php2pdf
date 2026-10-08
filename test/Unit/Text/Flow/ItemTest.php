<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Text\Flow;

use Php2Pdf\Text\Flow\Item;
use Php2Pdf\Text\Flow\ItemKind;
use Php2Pdf\Text\Style\TextStyle;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class ItemTest extends TestCase
{
    /** @return array<string, array{0: ItemKind, 1: bool}> */
    public static function breakOpportunityProvider(): array
    {
        return [
            'glue'       => [ItemKind::Glue, true],
            'penalty'    => [ItemKind::Penalty, true],
            'box'        => [ItemKind::Box, false],
            'tab'        => [ItemKind::Tab, false],
            'line break' => [ItemKind::LineBreak, false],
        ];
    }

    #[Test]
    #[DataProvider('breakOpportunityProvider')]
    public function onlyGlueAndPenaltiesAreBreakOpportunities(ItemKind $kind, bool $opportunity): void
    {
        self::assertSame($opportunity, (new Item($kind, new TextStyle()))->isBreakOpportunity());
    }
}
