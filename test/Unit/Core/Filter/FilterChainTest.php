<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Core\Filter;

use Php2Pdf\Core\Filter\FilterChain;
use Php2Pdf\Core\Object\Name;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[Group('unit')]
final class FilterChainTest extends TestCase
{
    /** @return array<string, array{string, string, string}> */
    public static function singleFilters(): array
    {
        return [
            'LZW without an end of data code' => ['LZWDecode', "\x20\x80", 'A'],
            'abbreviated LZW'                 => ['LZW', "\x20\x80", 'A'],
            'ASCII85'                         => ['ASCII85Decode', '9jqo^', 'Man '],
            'abbreviated ASCII85'             => ['A85', '9jqo^', 'Man '],
            'Crypt passes data through'       => ['Crypt', 'opaque', 'opaque'],
        ];
    }

    #[Test]
    #[DataProvider('singleFilters')]
    public function decodesThroughASingleFilter(string $filter, string $data, string $expected): void
    {
        self::assertSame($expected, FilterChain::decode($data, [$filter], [null]));
    }

    #[Test]
    public function skipsFilterEntriesThatAreNotNames(): void
    {
        self::assertSame(
            ['ASCIIHexDecode', 'FlateDecode'],
            FilterChain::filters([new Name('ASCIIHexDecode'), 'FlateDecode', 5, new Name('FlateDecode')]),
        );
    }
}
