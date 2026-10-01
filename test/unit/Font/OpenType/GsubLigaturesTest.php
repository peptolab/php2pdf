<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Font\OpenType;

use PhpPdf\Font\OpenType\BinaryReader;
use PhpPdf\Font\OpenType\GsubLigatures;
use PhpPdfTest\Support\GsubBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_column;
use function array_map;

/** Standard ligatures from the GSUB "liga" feature (#5). Glyphs: f 1, i 2, l 3, o 4. */
#[Group('unit')]
final class GsubLigaturesTest extends TestCase
{
    /** ffi 10, ff 11, fl 12, fi 13 */
    private const array LIGATURES = [1 => [[[1, 2], 10], [[1], 11], [[3], 12], [[2], 13]]];

    /** @return array<string, array{0: bool}> */
    public static function lookupProvider(): array
    {
        return ['a ligature lookup' => [false], 'an extension lookup' => [true]];
    }

    /**
     * @param array<int, int> $ids
     *
     * @return list<array{0: int, 1: list<int>}>
     */
    private static function glyphs(array $ids): array
    {
        $codePoints = [1 => 0x66, 2 => 0x69, 3 => 0x6C, 4 => 0x6F];

        return array_map(static fn(int $id): array => [$id, [$codePoints[$id] ?? 0]], $ids);
    }

    /**
     * @param list<array{0: int, 1: list<int>}> $glyphs
     *
     * @return list<int>
     */
    private static function ids(array $glyphs): array
    {
        return array_column($glyphs, 0);
    }

    /**
     * @param array<string, list<int>>                                                   $features
     * @param list<array{0: bool, 1: array<int, list<array{0: list<int>, 1: int}>>}> $lookups
     */
    private static function ligatures(string $script, array $features, array $lookups): GsubLigatures
    {
        return new GsubLigatures(new BinaryReader(GsubBuilder::build($script, $features, $lookups)));
    }

    #[Test]
    public function aLigatureStandsForAllItsCharacters(): void
    {
        $ligatures = self::ligatures('latn', ['liga' => [0]], [[false, self::LIGATURES]]);

        self::assertSame([[0x6F], [0x66, 0x66, 0x69]], array_column($ligatures->apply(self::glyphs([4, 1, 1, 2])), 1));
    }

    #[Test]
    public function lookupsApplyInOrder(): void
    {
        $ligatures = self::ligatures(
            'latn',
            ['liga' => [0, 1]],
            [
                [false, [1 => [[[1], 11]]]],
                [false, [11 => [[[2], 20]]]],
            ],
        );

        self::assertSame([20], self::ids($ligatures->apply(self::glyphs([1, 1, 2]))));
    }

    #[Test]
    public function otherFeaturesAreNotApplied(): void
    {
        self::assertFalse(self::ligatures('latn', ['dlig' => [0]], [[false, self::LIGATURES]])->hasLigatures());
    }

    #[Test]
    public function otherScriptsAreNotApplied(): void
    {
        self::assertFalse(self::ligatures('cyrl', ['liga' => [0]], [[false, self::LIGATURES]])->hasLigatures());
    }

    #[Test]
    #[DataProvider('lookupProvider')]
    public function substitutesTheLongestLigatureFirst(bool $extension): void
    {
        $ligatures = self::ligatures('latn', ['liga' => [0]], [[$extension, self::LIGATURES]]);

        self::assertSame([4, 10, 12, 1], self::ids($ligatures->apply(self::glyphs([4, 1, 1, 2, 1, 3, 1]))));
    }

    #[Test]
    public function theDefaultScriptServesFontsWithoutLatin(): void
    {
        self::assertTrue(self::ligatures('DFLT', ['liga' => [0]], [[false, self::LIGATURES]])->hasLigatures());
    }
}
