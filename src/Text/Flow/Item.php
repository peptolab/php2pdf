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
        /** For penalties: the hyphen's width, with the kerning change below, in points. */
        public float $hyphenWidth = 0.0,
        /**
         * For penalties: the change, in thousandths of an em, to the kerning of the glyph before
         * the break when the hyphen follows it instead of the next piece.
         */
        public float $hyphenKerning = 0.0,
        /** For inline items: the artwork and its size. */
        public ?InlineObject $inline = null,
    ) {}

    /** An item that sets artwork in the line. */
    public static function inline(InlineObject $inline, TextStyle $style): self
    {
        return new self(ItemKind::Inline, $style, width: $inline->width, inline: $inline);
    }

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
