<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\Composer;
use Php2Pdf\Text\Style\TextStyle;

use function array_key_exists;
use function array_splice;
use function array_values;
use function max;

/**
 * A paragraph: its resolved style, the items that make up its text, and its direction as a bidi
 * level. Items may be split when a word is wider than a line.
 *
 * @internal
 */
final class Paragraph
{
    /** @var array{0: string, 1: array<int, LineEnd>}|null the composer's plan: widths, and line start => line end */
    private ?array $plan = null;

    /**
     * @param list<Item> $items
     * @param int        $level the bidi paragraph level: 0 left to right, 1 right to left
     */
    public function __construct(
        public readonly TextStyle $style,
        public array $items,
        public readonly int $level = 0,
    ) {}

    /**
     * Where the line after an end starts: past a forced break and the glue a line broke at.
     *
     * @param list<Item> $items
     */
    private static function nextStart(array $items, LineEnd $end): int
    {
        $next = $end->forced ? $end->index + 1 : $end->index;
        while (ItemKind::Glue === ($items[$next] ?? null)?->kind) {
            ++$next;
        }

        return $next;
    }

    /**
     * The end of the line starting at $start by the paragraph composer, when the paragraph uses it:
     * from the plan of its breaks, made again when the line does not start at a planned break or
     * the width differs. Null when the paragraph is set line by line, or the composer cannot set it.
     *
     * @param float $width     the frame's width, before the paragraph's indents
     * @param bool  $firstLine whether this is the paragraph's first line, with its first-line indent
     */
    public function plannedEnd(int $start, float $width, bool $firstLine): ?LineEnd
    {
        $style    = $this->style;
        $composer = $style->composer
            ?? (Alignment::Justify === $style->alignment ? Composer::Paragraph : Composer::SingleLine);
        if (Composer::SingleLine === $composer) {
            return null;
        }
        $width      = max(1.0, $width - ($style->leftIndent ?? 0.0) - ($style->rightIndent ?? 0.0));
        $firstWidth = $firstLine ? max(1.0, $width - ($style->firstLineIndent ?? 0.0)) : $width;
        $key        = "{$firstWidth}:{$width}";
        if (null === $this->plan || $key !== $this->plan[0] || ! array_key_exists($start, $this->plan[1])) {
            $ends = ParagraphComposer::breaks($this, $start, $firstWidth, $width);
            if (null === $ends) {
                return null;
            }
            $plan = [];
            $from = $start;
            foreach ($ends as $end) {
                $plan[$from] = $end;
                $from        = self::nextStart($this->items, $end);
            }
            $this->plan = [$key, $plan];
        }

        return $this->plan[1][$start] ?? null;
    }

    /**
     * Replaces the item at $index with the given pieces.
     *
     * @param list<Item> $pieces
     */
    public function split(int $index, array $pieces): void
    {
        array_splice($this->items, $index, length: 1, replacement: $pieces);
        $this->items = array_values($this->items);
        $this->plan  = null;
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
