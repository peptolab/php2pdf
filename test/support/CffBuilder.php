<?php

declare(strict_types=1);

namespace Php2PdfTest\Support;

use Php2Pdf\Font\OpenType\Cff\Dict;

use function chr;
use function count;
use function implode;
use function strlen;

/**
 * Assembles small CFF tables for tests: name-keyed or CID-keyed, with chosen charstrings,
 * subroutines, FDSelect data and extra Top DICT entries.
 */
final class CffBuilder
{
    private const int OP_PRIVATE     = 18;
    private const int OP_SUBRS       = 19;
    private const int OP_CHARSTRINGS = 17;
    private const int OP_ROS         = 1230;
    private const int OP_FD_ARRAY    = 1236;
    private const int OP_FD_SELECT   = 1237;

    /**
     * @param list<string>       $charStrings
     * @param string             $fdSelect    raw FDSelect data
     * @param list<list<string>> $fdSubrs     local subrs of each Font DICT
     * @param list<string>       $globalSubrs
     * @param list<array<int, string>> $fdExtra extra entries for each Font DICT, in order
     * @param array<int, string>       $top     extra Top DICT entries
     */
    public static function cidKeyed(
        array $charStrings,
        string $fdSelect,
        array $fdSubrs,
        array $globalSubrs = [],
        array $fdExtra = [],
        array $top = [],
    ): string {
        $charStrings = Dict::index($charStrings);
        $privates    = [];
        foreach ($fdSubrs as $subrs) {
            $privates[] = self::privateDict($subrs) . ([] === $subrs ? '' : Dict::index($subrs));
        }
        $topDict = static fn(int $cs, int $select, int $fdArray): string => Dict::encode([
            self::OP_ROS => Dict::int(391) . Dict::int(392) . Dict::int(0),
        ]
            + $top
            + [
                self::OP_CHARSTRINGS => Dict::int32($cs),
                self::OP_FD_SELECT   => Dict::int32($select),
                self::OP_FD_ARRAY    => Dict::int32($fdArray),
            ]);
        $fdDicts = static function (int $offset) use ($fdSubrs, $fdExtra): array {
            $dicts = [];
            foreach ($fdSubrs as $fd => $subrs) {
                $size    = strlen(self::privateDict($subrs));
                $dicts[] = Dict::encode(
                    ($fdExtra[$fd] ?? []) + [self::OP_PRIVATE => Dict::int32($size) . Dict::int32($offset)],
                );
                $offset += $size + ([] === $subrs ? 0 : strlen(Dict::index($subrs)));
            }

            return $dicts;
        };
        $head = self::head(
            1,
            ['Test'],
            $topDict(
                cs: 0,
                select: 0,
                fdArray: 0,
            ),
            $globalSubrs,
        );
        $selectOffset  = strlen($head);
        $charsOffset   = $selectOffset + strlen($fdSelect);
        $arrayOffset   = $charsOffset + strlen($charStrings);
        $privateOffset = $arrayOffset + strlen(Dict::index($fdDicts(0)));

        return (
            self::head(1, ['Test'], $topDict($charsOffset, $selectOffset, $arrayOffset), $globalSubrs)
                . $fdSelect
                . $charStrings
                . Dict::index($fdDicts($privateOffset))
                . implode('', $privates)
        );
    }

    /**
     * @param list<string>       $charStrings
     * @param list<string>       $globalSubrs
     * @param list<string>       $localSubrs
     * @param array<int, string>      $top         extra Top DICT entries: operator => encoded operands
     * @param list<string>            $names       the Name INDEX
     * @param array<int, string>|null $private     extra Private DICT entries, or null for no Private DICT
     */
    public static function nameKeyed(
        array $charStrings,
        array $globalSubrs = [],
        array $localSubrs = [],
        array $top = [],
        array $names = ['Test'],
        int $version = 1,
        ?array $private = [],
    ): string {
        $hasPrivate  = null !== $private;
        $extra       = Dict::encode($private ?? []);
        $private     = $extra . self::privateDict($localSubrs, strlen($extra));
        $subrs       = [] === $localSubrs ? '' : Dict::index($localSubrs);
        $charStrings = Dict::index($charStrings);
        $topDict     = static fn(int $cs, int $pv): string => Dict::encode(
            $top
                + [
                    self::OP_CHARSTRINGS => Dict::int32($cs),
                ]
                + ($hasPrivate ? [self::OP_PRIVATE => Dict::int32(strlen($private)) . Dict::int32($pv)] : []),
        );
        $head = self::head(
            $version,
            $names,
            $topDict(
                cs: 0,
                pv: 0,
            ),
            $globalSubrs,
        );
        $charsOffset = strlen($head);
        $privOffset  = $charsOffset + strlen($charStrings);

        return (
            self::head($version, $names, $topDict($charsOffset, $privOffset), $globalSubrs)
                . $charStrings
                . $private
                . $subrs
        );
    }

    /**
     * @param list<string> $names
     * @param list<string> $globalSubrs
     */
    private static function head(int $version, array $names, string $topDict, array $globalSubrs): string
    {
        $topDicts = 1 === count($names) ? [$topDict] : [$topDict, $topDict];

        return (
            chr($version)
                . "\x00\x04\x04"
                . Dict::index($names)
                . Dict::index($topDicts)
                . Dict::index([])
                . Dict::index($globalSubrs)
        );
    }

    /**
     * The Subrs entry of a Private DICT, pointing just past a DICT that ends with it.
     *
     * @param list<string> $subrs
     */
    private static function privateDict(array $subrs, int $before = 0): string
    {
        return [] === $subrs ? '' : Dict::encode([self::OP_SUBRS => Dict::int32($before + 6)]);
    }
}
