<?php

declare(strict_types=1);

namespace PhpPdf\Text\Flow;

use PhpPdf\Text\Style\TextStyle;

use function array_splice;
use function array_values;

/**
 * A paragraph: its resolved style and the items that make up its text. Items may be split when a
 * word is wider than a line.
 *
 * @internal
 */
final class Paragraph
{
    /** @param list<Item> $items */
    public function __construct(
        public readonly TextStyle $style,
        public array $items,
    ) {}

    /**
     * Replaces the item at $index with the given pieces.
     *
     * @param list<Item> $pieces
     */
    public function split(int $index, array $pieces): void
    {
        array_splice($this->items, $index, length: 1, replacement: $pieces);
        $this->items = array_values($this->items);
    }

    public function text(): string
    {
        $text = '';
        foreach ($this->items as $item) {
            $text .= match ($item->kind) {
                ItemKind::Tab       => "\t",
                ItemKind::LineBreak => "\n",
                default             => $item->text,
            };
        }

        return $text;
    }
}
