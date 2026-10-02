<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Text\Style\TextStyle;

/**
 * A paragraph: its resolved style, the items that make up its text, and its direction as a bidi
 * level. Items may be split when a word is wider than a line.
 *
 * @internal
 */
final class Paragraph
{
    /**
     * @param list<Item> $items
     * @param int        $level the bidi paragraph level: 0 left to right, 1 right to left
     */
    public function __construct(
        public readonly TextStyle $style,
        public readonly array $items,
        public readonly int $level = 0,
    ) {}

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
