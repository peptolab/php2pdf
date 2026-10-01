<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use function count;

/**
 * A sequence of shaped glyphs from one font, with its width in thousandths of an em.
 *
 * @api
 */
final readonly class GlyphRun
{
    /** @param list<Glyph> $glyphs */
    public function __construct(
        public array $glyphs,
    ) {}

    public function count(): int
    {
        return count($this->glyphs);
    }

    public function isEmpty(): bool
    {
        return [] === $this->glyphs;
    }

    /** Width in points at the given size, including kerning, spacing and tracking. */
    public function measure(float $size, TextOptions $options): float
    {
        $count = $this->count();
        $width = ((($this->width() + ($options->tracking * $count)) / 1000.0) * $size)
        + ($options->charSpacing * $count)
        + ($options->wordSpacing * $this->spaceCount());

        return ($width * $options->horizontalScale) / 100.0;
    }

    public function spaceCount(): int
    {
        $count = 0;
        foreach ($this->glyphs as $glyph) {
            if (! $glyph->isSpace()) {
                continue;
            }
            ++$count;
        }

        return $count;
    }

    /** Width in thousandths of an em, including kerning. */
    public function width(): float
    {
        $width = 0.0;
        foreach ($this->glyphs as $glyph) {
            $width += $glyph->advance + $glyph->kerning;
        }

        return $width;
    }
}
