<?php

declare(strict_types=1);

namespace PhpPdf\Text\Flow;

use PhpPdf\Font\GlyphRun;
use PhpPdf\Text\Style\TextStyle;

/**
 * One element of an itemised paragraph. Widths are in points at the item's style.
 *
 * @internal
 */
final readonly class Item
{
    public function __construct(
        public ItemKind $kind,
        public TextStyle $style,
        public string $text = '',
        public ?GlyphRun $run = null,
        public float $width = 0.0,
        /** For penalties: the hyphen shown when the line breaks here. */
        public ?GlyphRun $hyphenRun = null,
        public float $hyphenWidth = 0.0,
    ) {}

    public function isBreakOpportunity(): bool
    {
        return ItemKind::Glue === $this->kind || ItemKind::Penalty === $this->kind;
    }

    public function isForcedBreak(): bool
    {
        return (
            ItemKind::LineBreak === $this->kind
                || ItemKind::ColumnBreak === $this->kind
                || ItemKind::PageBreak === $this->kind
        );
    }
}
