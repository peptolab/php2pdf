<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use function array_reverse;
use function array_slice;
use function array_splice;
use function count;
use function max;
use function min;

/**
 * Puts a line's items in visual order by their bidi levels (rules L1 and L2): glue at the end of
 * the line and tabs, with the glue before them, go to the paragraph level; then from the highest
 * level down to the lowest odd one, each run of items at that level or above is reversed. Each
 * item's glyphs are already in visual order.
 *
 * @internal
 */
final class BidiLine
{
    /**
     * @param list<Item> $items in logical order
     *
     * @return list<Item>
     */
    public static function visual(array $items, int $paragraphLevel): array
    {
        $levels = [];
        foreach ($items as $item) {
            $levels[] = $item->level;
        }
        if ([] === $levels || (0 === $paragraphLevel && 0 === max($levels))) {
            return $items;
        }
        $levels = self::resetSeparators($items, $levels, $paragraphLevel);
        $count  = count($items);
        for ($level = max($levels); $level >= (min($levels) | 1); --$level) {
            for ($i = 0; $i < $count;) {
                if (($levels[$i] ?? 0) < $level) {
                    ++$i;
                    continue;
                }
                $j = $i;
                while ($j < $count && ($levels[$j] ?? 0) >= $level) {
                    ++$j;
                }
                array_splice($items, $i, $j - $i, array_reverse(array_slice($items, $i, $j - $i)));
                array_splice($levels, $i, $j - $i, array_reverse(array_slice($levels, $i, $j - $i)));
                $i = $j;
            }
        }

        return $items;
    }

    /**
     * Rule L1 for items: tabs, and glue before a tab or at the end of the line, take the
     * paragraph level.
     *
     * @param list<Item> $items
     * @param list<int>  $levels
     *
     * @return list<int>
     */
    private static function resetSeparators(array $items, array $levels, int $paragraphLevel): array
    {
        $trailing = true;
        for ($k = count($items) - 1; $k >= 0; --$k) {
            $kind = ($items[$k] ?? null)?->kind;
            if (ItemKind::Tab === $kind) {
                $levels[$k] = $paragraphLevel;
                $trailing   = true;
                continue;
            }
            if ($trailing && ItemKind::Glue === $kind) {
                $levels[$k] = $paragraphLevel;
                continue;
            }
            $trailing = false;
        }

        return $levels;
    }
}
