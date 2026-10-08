<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset\Font;

use Php2Pdf\Font\OpenType\Cff\Dict;

use function chr;
use function count;
use function implode;
use function pack;
use function strlen;

/**
 * Assembles small CFF2 tables for tests: chosen charstrings, one Font DICT per raw Private DICT,
 * optional raw FDSelect data and a variation store giving each item variation data's region count.
 * The global subrs INDEX is always empty.
 */
final class Cff2Builder
{
    private const int OP_CHARSTRINGS = 17;
    private const int OP_PRIVATE     = 18;
    private const int OP_VSTORE      = 24;
    private const int OP_FD_ARRAY    = 1236;
    private const int OP_FD_SELECT   = 1237;

    /**
     * @param list<string> $charStrings
     * @param list<string> $privates     the encoded Private DICT of each Font DICT
     * @param string|null  $fdSelect     raw FDSelect data, or null for no FDSelect
     * @param list<int>    $regionCounts the region count of each item variation data, or none for no variation store
     */
    public static function table(
        array $charStrings,
        array $privates = [''],
        ?string $fdSelect = null,
        array $regionCounts = [],
        int $version = 2,
    ): string {
        $top = static fn(int $chars, int $fdArray, int $select, int $store): string => Dict::encode(
            [self::OP_CHARSTRINGS => Dict::int32($chars), self::OP_FD_ARRAY => Dict::int32($fdArray)]
                + (null === $fdSelect ? [] : [self::OP_FD_SELECT => Dict::int32($select)])
                + ([] === $regionCounts ? [] : [self::OP_VSTORE => Dict::int32($store)]),
        );
        $topLength = strlen($top(
            chars: 0,
            fdArray: 0,
            select: 0,
            store: 0,
        ));
        $globalSubrs = self::index([]);
        $chars       = self::index($charStrings);
        $charsAt     = 5 + $topLength + strlen($globalSubrs);
        $arrayAt     = $charsAt + strlen($chars);
        $selectAt    = $arrayAt + strlen(self::index(self::fontDicts($privates, offset: 0)));
        $select      = $fdSelect ?? '';
        $storeAt     = $selectAt + strlen($select);
        $store       = [] === $regionCounts ? '' : self::variationStore($regionCounts);

        return (
            chr($version)
                . "\x00\x05"
                . pack('n', $topLength)
                . $top($charsAt, $arrayAt, $selectAt, $storeAt)
                . $globalSubrs
                . $chars
                . self::index(self::fontDicts($privates, $storeAt + strlen($store)))
                . $select
                . $store
                . implode('', $privates)
        );
    }

    /**
     * Font DICTs pointing at Private DICTs laid out one after another from $offset.
     *
     * @param list<string> $privates
     *
     * @return list<string>
     */
    private static function fontDicts(array $privates, int $offset): array
    {
        $dicts = [];
        foreach ($privates as $private) {
            $dicts[] = Dict::encode([self::OP_PRIVATE => Dict::int32(strlen($private)) . Dict::int32($offset)]);
            $offset  += strlen($private);
        }

        return $dicts;
    }

    /**
     * A CFF2 INDEX: a 32-bit count, then four-byte offsets.
     *
     * @param list<string> $items
     */
    private static function index(array $items): string
    {
        if ([] === $items) {
            return pack('N', 0);
        }
        $offsets = [1];
        $last    = 1;
        foreach ($items as $item) {
            $last      += strlen($item);
            $offsets[] = $last;
        }

        return pack('NC', count($items), 4) . pack('N*', ...$offsets) . implode('', $items);
    }

    /**
     * A variation store, preceded by its length: no region list, and item variation data with no
     * items, each over the given number of regions.
     *
     * @param list<int> $regionCounts
     */
    private static function variationStore(array $regionCounts): string
    {
        $offsets = [];
        $data    = '';
        $base    = 8 + (4 * count($regionCounts));
        foreach ($regionCounts as $regions) {
            $offsets[] = $base + strlen($data);
            $data      .= pack('nnn', 0, 0, $regions);
            for ($region = 0; $region < $regions; ++$region) {
                $data .= pack('n', $region);
            }
        }
        $store = pack('nNn', 1, 0, count($regionCounts)) . pack('N*', ...$offsets) . $data;

        return pack('n', strlen($store)) . $store;
    }
}
