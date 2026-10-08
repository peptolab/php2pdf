<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Text\Style\CharFormat;
use Php2Pdf\Text\Style\TextStyle;

use function count;

/**
 * One element of an itemised paragraph. Widths are in points at the item's style.
 *
 * @internal
 */
final readonly class Item
{
    /** The item's style, resolved. */
    public CharFormat $format;

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
        /** The bidi embedding level: even left to right, odd right to left. */
        public int $level = 0,
    ) {
        $this->format = CharFormat::of($style);
    }

    /** An item that sets artwork in the line. */
    public static function inline(InlineObject $inline, TextStyle $style): self
    {
        return new self(ItemKind::Inline, $style, width: $inline->width, inline: $inline);
    }

    /** How many glyphs the item shows: each takes letter spacing after it in justification. */
    public function glyphCount(): int
    {
        return null === $this->run ? 0 : count($this->run->glyphs);
    }

    public function isForcedBreak(): bool
    {
        return (
            ItemKind::LineBreak === $this->kind
                || ItemKind::ColumnBreak === $this->kind
                || ItemKind::PageBreak === $this->kind
        );
    }

    /** How far the space after each of the item's glyphs may close up in justification, in points. */
    public function letterShrink(): float
    {
        return 0.0 === $this->format->letterShrink
            ? 0.0
            : $this->format->letterShrink * ItemFactory::spaceWidth($this->style);
    }

    /** How far the space after each of the item's glyphs may open up in justification, in points. */
    public function letterStretch(): float
    {
        return 0.0 === $this->format->letterStretch
            ? 0.0
            : $this->format->letterStretch * ItemFactory::spaceWidth($this->style);
    }

    /**
     * The width of a word space's glyphs at its size, without the kerning towards the word after
     * it: what justification stretches and shrinks it by. The item's width for other items.
     */
    public function spaceWidth(): float
    {
        if (ItemKind::Glue !== $this->kind || null === $this->run) {
            return $this->width;
        }
        $advance = 0;
        foreach ($this->run->glyphs as $glyph) {
            $advance += $glyph->advance;
        }

        return (($advance / 1000.0) * $this->format->size * $this->format->horizontalScale) / 100.0;
    }

    /** The same item at a bidi level. */
    public function withLevel(int $level): self
    {
        return new self(
            $this->kind,
            $this->style,
            $this->text,
            $this->run,
            $this->width,
            $this->hyphenRun,
            $this->hyphenWidth,
            $this->hyphenKerning,
            $this->inline,
            $level,
        );
    }
}
