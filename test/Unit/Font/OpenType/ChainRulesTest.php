<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Font\OpenType;

use Php2Pdf\Core\BinaryReader;
use Php2Pdf\Font\OpenType\ChainRules;
use Php2Pdf\Font\OpenType\GlyphFilter;
use Php2Pdf\Font\OpenType\LayoutTables;
use Php2PdfTest\TestAsset\Font\LayoutSubtableBuilder;
use Php2PdfTest\TestAsset\GsubBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Context (GSUB type 5) and chained context (type 6) rules matched directly at a position of a
 * glyph sequence, the subtable being the only one of a GSUB table's only lookup.
 */
#[Group('unit')]
final class ChainRulesTest extends TestCase
{
    /** @return array<string, array{0: int, 1: string, 2: list<int>, 3: int}> */
    public static function rejectionProvider(): array
    {
        $byGlyph      = GsubBuilder::context1([5 => [[[9], [[0, 1]]]]]);
        $rules        = [[[], [], [], [[0, 1]]]];
        $digitClasses = [5 => 1];

        return [
            'by coverage, chained: the input runs past the end'  => [
                6,
                GsubBuilder::chain3([], [[5], [9]], [], [[0, 1]]),
                [8, 5],
                1,
            ],
            'by coverage: a later input glyph is not covered'    => [
                5,
                GsubBuilder::context3([[5], [9]], [[0, 1]]),
                [5, 8],
                0,
            ],
            'by coverage: no input'                              => [5, GsubBuilder::context3([], []), [5], 0],
            'by glyph: the glyph is not covered'                 => [5, $byGlyph, [6, 9], 0],
            'by glyph: the glyph has no rule set'                => [
                5,
                LayoutSubtableBuilder::covered(1, [5], 0),
                [5],
                0,
            ],
            'by glyph: no rule matches the input'                => [5, $byGlyph, [5, 8], 0],
            'by glyph: the input runs past the end'              => [5, $byGlyph, [5], 0],
            'by class: the glyph is not covered'                 => [
                6,
                GsubBuilder::chain2([5], [], $digitClasses, [], [1 => $rules]),
                [6],
                0,
            ],
            'by class: the glyph\'s class is past the rule sets' => [
                6,
                GsubBuilder::chain2([5], [], [5 => 3], [], [1 => $rules]),
                [5],
                0,
            ],
            'by class: the glyph\'s class has no rule set'       => [
                6,
                GsubBuilder::chain2([5], [], $digitClasses, [], [2 => $rules]),
                [5],
                0,
            ],
        ];
    }

    /**
     * @param list<int> $ids
     *
     * @return array{0: list<int>, 1: list<array{0: int, 1: int}>}|null
     */
    private static function match(int $type, string $subtable, array $ids, int $i): ?array
    {
        $tables = LayoutTables::gsub(
            new BinaryReader(GsubBuilder::build('latn', ['test' => [0]], [[$type, [$subtable]]])),
            null,
        );
        $offset = $tables->subtables(0)[0][1] ?? 0;

        return (new ChainRules($tables))->match($type, $offset, $ids, $i, new GlyphFilter(null));
    }

    #[Test]
    public function aContextRuleByClassMatchesItsInputClasses(): void
    {
        $subtable = LayoutSubtableBuilder::context2([5, 6], [5 => 1, 6 => 1, 9 => 2], [1 => [[[2], [[0, 1]]]]]);

        self::assertSame([[0, 1], [[0, 1]]], self::match(5, $subtable, [6, 9], 0));
    }

    /** @param list<int> $ids */
    #[Test]
    #[DataProvider('rejectionProvider')]
    public function aRuleThatDoesNotFitTheSequenceDoesNotMatch(int $type, string $subtable, array $ids, int $i): void
    {
        self::assertNull(self::match($type, $subtable, $ids, $i));
    }
}
