<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use function array_slice;
use function count;
use function implode;
use function intdiv;
use function max;
use function mb_str_split;
use function mb_strlen;
use function mb_substr;

/**
 * Splitting a box too wide for any line between characters. The paragraph keeps the box whole:
 * a line takes the box's head in its place, and the layout's position counts the characters of
 * the box already placed (its carry), so the next line starts with the rest.
 *
 * @internal
 */
final class BoxSplit
{
    /** The paragraph from a position: the item there cut to what is left after $carry characters. */
    public static function from(Paragraph $paragraph, int $offset, int $carry): Paragraph
    {
        return new Paragraph(
            $paragraph->style,
            array_slice(self::resume($paragraph->items, $offset, $carry), $offset),
            $paragraph->level,
            $paragraph->list,
        );
    }

    /** The head of a box: as many characters as fit the width, at least one. */
    public static function head(Item $item, float $width): Item
    {
        $chars = mb_str_split($item->text, length: 1, encoding: 'UTF-8');
        $lo    = 1;
        $hi    = max(1, count($chars) - 1);
        while ($lo < $hi) {
            $mid = intdiv($lo + $hi + 1, num2: 2);
            $box = ItemFactory::box(
                implode('', array_slice($chars, offset: 0, length: $mid)),
                $item->style,
                $item->level,
            );
            if ($box->width > $width) {
                $hi = $mid - 1;
                continue;
            }
            $lo = $mid;
        }

        return ItemFactory::box(implode('', array_slice($chars, offset: 0, length: $lo)), $item->style, $item->level);
    }

    /**
     * The items of a line ending at a split box, with the head in the box's place, and where the
     * next line starts: the whole items taken from $offset and the carry into the box. A head that
     * is the whole box takes it whole.
     *
     * @param list<Item> $items
     *
     * @return array{0: list<Item>, 1: array{0: int, 1: int}}
     */
    public static function line(array $items, LineEnd $end, int $offset, int $carry): array
    {
        $split = $end->index - 1;
        $box   = $items[$split] ?? null;
        $head  = $end->head;
        if (null === $box || null === $head) {
            return [$items, [$end->index - $offset, 0]];
        }
        $items[$split] = $head;
        $length        = mb_strlen($head->text, encoding: 'UTF-8');
        if ($length >= mb_strlen($box->text, encoding: 'UTF-8')) {
            return [$items, [$end->index - $offset, 0]];
        }

        return [$items, [$split - $offset, ($split === $offset ? $carry : 0) + $length]];
    }

    /** What is left of a box once its first $carry characters are on earlier lines. */
    public static function rest(Item $item, int $carry): Item
    {
        return ItemFactory::box(mb_substr($item->text, $carry, encoding: 'UTF-8'), $item->style, $item->level);
    }

    /**
     * The items with the one at $offset cut to what is left after $carry characters.
     *
     * @param list<Item> $items
     *
     * @return list<Item>
     */
    public static function resume(array $items, int $offset, int $carry): array
    {
        $item = $items[$offset] ?? null;
        if (0 === $carry || null === $item) {
            return $items;
        }
        $items[$offset] = self::rest($item, $carry);

        return $items;
    }
}
