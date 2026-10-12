<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Table;

use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Table\TableFitInfo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** What a table's fit reports: where its last placed edge is, and whether rows are left over. */
#[Group('unit')]
final class TableFitInfoTest extends TestCase
{
    /** @return array<string, array{0: list<float>, 1: float|null}> the row edges, and the bottom */
    public static function bottomProvider(): array
    {
        return [
            'rows placed'    => [[700.0, 680.0, 655.5], 655.5],
            'nothing placed' => [[], null],
        ];
    }

    /** @param list<float> $rowEdges */
    #[Test]
    #[DataProvider('bottomProvider')]
    public function theBottomIsTheLastRowEdge(array $rowEdges, ?float $bottom): void
    {
        self::assertSame($bottom, (new TableFitInfo(FlowResult::Done, 44.5, 2, $rowEdges, [0.0, 100.0]))->bottom());
    }
}
