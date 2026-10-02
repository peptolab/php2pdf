<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

/**
 * How a justified line takes its slack: what each word space gains or loses, what each glyph of
 * an item gains or loses after it (all but the line's last glyph), and the factor its glyphs are
 * scaled by. Widths are before scaling: the scale applies to them as it does to the glyphs.
 *
 * @internal
 */
final readonly class LineSpread
{
    /**
     * @param array<int, float> $words   the change to the word space at each index
     * @param array<int, float> $letters the change after each glyph of the item at each index
     * @param int|null          $last    the index of the item that shows the line's last glyph
     */
    public function __construct(
        public array $words = [],
        public array $letters = [],
        public float $scale = 1.0,
        public ?int $last = null,
    ) {}

    /** Whether the line is justified at all. */
    public function justifies(): bool
    {
        return [] !== $this->words || [] !== $this->letters || 1.0 !== $this->scale;
    }
}
