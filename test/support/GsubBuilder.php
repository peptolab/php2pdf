<?php

declare(strict_types=1);

namespace PhpPdfTest\Support;

use function array_keys;
use function count;
use function pack;
use function range;
use function sort;
use function strlen;

/**
 * A minimal GSUB table: one script with a default language system whose features are "liga"
 * (and optionally others), each pointing at ligature substitution lookups, plain (type 4) or
 * wrapped in an extension lookup (type 7).
 */
final class GsubBuilder
{
    /**
     * @param string                                         $script   the script tag, e.g. "latn" or "DFLT"
     * @param array<string, list<int>>                       $features feature tag => lookup indices
     * @param list<array{0: bool, 1: array<int, list<array{0: list<int>, 1: int}>>}> $lookups
     *        per lookup: whether it is wrapped in an extension, and first glyph => (other components, ligature)
     */
    public static function build(string $script, array $features, array $lookups): string
    {
        $header      = 10;
        $scriptList  = self::scriptList($script, count($features));
        $featureList = self::featureList($features);
        $lookupList  = self::lookupList($lookups);
        $scriptAt    = $header;
        $featureAt   = $scriptAt + strlen($scriptList);
        $lookupAt    = $featureAt + strlen($featureList);

        return pack('nnnnn', 1, 0, $scriptAt, $featureAt, $lookupAt) . $scriptList . $featureList . $lookupList;
    }

    /** @param array<string, list<int>> $features */
    private static function featureList(array $features): string
    {
        $records = '';
        $tables  = '';
        $base    = 2 + (6 * count($features));
        foreach ($features as $tag => $indices) {
            $records .= $tag . pack('n', $base + strlen($tables));
            $tables  .= pack('nn', 0, count($indices)) . pack('n*', ...$indices);
        }

        return pack('n', count($features)) . $records . $tables;
    }

    /** @param array<int, list<array{0: list<int>, 1: int}>> $ligatures */
    private static function ligatureSubst(array $ligatures): string
    {
        $firsts = array_keys($ligatures);
        sort($firsts);
        $setCount   = count($firsts);
        $coverageAt = 6 + (2 * $setCount);
        $coverage   = pack('nn', 1, $setCount) . pack('n*', ...$firsts);
        $sets       = '';
        $setOffsets = [];
        $setsAt     = $coverageAt + strlen($coverage);
        foreach ($firsts as $first) {
            $setOffsets[] = $setsAt + strlen($sets);
            $entries      = $ligatures[$first];
            $ligTables    = '';
            $ligOffsets   = [];
            $base         = 2 + (2 * count($entries));
            foreach ($entries as [$components, $ligature]) {
                $ligOffsets[] = $base + strlen($ligTables);
                $ligTables    .= pack('nn', $ligature, count($components) + 1) . pack('n*', ...$components);
            }
            $sets .= pack('n', count($entries)) . pack('n*', ...$ligOffsets) . $ligTables;
        }

        return pack('nnn', 1, $coverageAt, $setCount) . pack('n*', ...$setOffsets) . $coverage . $sets;
    }

    /** @param list<array{0: bool, 1: array<int, list<array{0: list<int>, 1: int}>>}> $lookups */
    private static function lookupList(array $lookups): string
    {
        $tables  = '';
        $offsets = [];
        $base    = 2 + (2 * count($lookups));
        foreach ($lookups as [$extension, $ligatures]) {
            $offsets[] = $base + strlen($tables);
            $subtable  = self::ligatureSubst($ligatures);
            $tables    .= $extension
                ? pack('nnnn', 7, 0, 1, 8) . pack('nnN', 1, 4, 8) . $subtable
                : pack('nnnn', 4, 0, 1, 8) . $subtable;
        }

        return pack('n', count($lookups)) . pack('n*', ...$offsets) . $tables;
    }

    private static function scriptList(string $script, int $featureCount): string
    {
        $langSys =
            pack('nnn', 0, 0xFFFF, $featureCount)
            . ($featureCount > 0 ? pack('n*', ...range(0, $featureCount - 1)) : '');
        $scriptTable = pack('nn', 4, 0) . $langSys;

        return pack('n', 1) . $script . pack('n', 8) . $scriptTable;
    }
}
