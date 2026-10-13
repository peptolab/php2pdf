<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Text\Style\ListMarker;
use Php2Pdf\Text\Style\TextStyle;

use function array_pop;
use function count;

/**
 * The lists open while markup or a builder gives a flow's paragraphs: each list's marker, the
 * number of its last item and its item open, if any, so each paragraph learns where it sits and
 * an item's first paragraph its label. Lists and items are numbered across the whole flow.
 *
 * @internal
 */
final class ListContext
{
    /**
     * @var list<array{id: int, marker: ListMarker, number: int, item: ?int, style: ?TextStyle}> the
     *      lists open, outermost first
     */
    private array $lists = [];

    /** The next number a list or item takes. */
    private int $next = 0;

    /** The label of the item just started, until its first paragraph takes it. */
    private ?string $label = null;

    /** Closes the innermost list; nothing when none is open. */
    public function close(): void
    {
        array_pop($this->lists);
        $this->label = null;
    }

    /** Ends the innermost list's open item. */
    public function closeItem(): void
    {
        $last = count($this->lists) - 1;
        if ($last >= 0) {
            $this->lists[$last]['item'] = null;
        }
        $this->label = null;
    }

    /** Whether an item is open, whose text forms its paragraphs. */
    public function isInItem(): bool
    {
        foreach ($this->lists as $list) {
            if (null !== $list['item']) {
                return true;
            }
        }

        return false;
    }

    /**
     * Starts the innermost list's next item; its paragraph style, the list's, or null outside a
     * list, where an item is a paragraph of its own.
     */
    public function item(): ?TextStyle
    {
        $last = count($this->lists) - 1;
        $list = $this->lists[$last] ?? null;
        if (null === $list) {
            return null;
        }
        ++$list['number'];
        $list['item']       = $this->next++;
        $this->lists[$last] = $list;
        $this->label        = $list['marker']->label($list['number']);

        return $list['style'];
    }

    /**
     * Opens a list inside the open item, if any: with its marker, or for none a bullet by its depth;
     * its first item numbered $start.
     */
    public function open(?ListMarker $marker, int $start = 1, ?TextStyle $style = null): void
    {
        $this->lists[] = [
            'id'     => $this->next++,
            'marker' => $marker ?? ListMarker::bullet(count($this->lists) + 1),
            'number' => $start - 1,
            'item'   => null,
            'style'  => $style,
        ];
    }

    /**
     * Where the next paragraph sits: in the open items, outermost first, with the label of an
     * item that has none yet; null outside any item.
     */
    public function position(): ?ListPosition
    {
        $path = [];
        foreach ($this->lists as $list) {
            if (null === $list['item']) {
                continue;
            }
            $path[] = [$list['id'], $list['item'], $list['marker']];
        }
        if ([] === $path) {
            return null;
        }
        $label       = $this->label;
        $this->label = null;

        return new ListPosition($path, $label);
    }
}
