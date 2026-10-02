<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use function count;
use function mb_chr;

/**
 * One positioned glyph in a shaped run. Advance, kerning and offsets are in thousandths of an em.
 * The offsets move the glyph alone, as mark positioning places an accent over its base; kerning
 * moves everything after it.
 *
 * @api
 */
final readonly class Glyph
{
    /**
     * @param int       $codePoint  the character the glyph stands for; -1 for a glyph that stands
     *                              for no text (a colour layer)
     * @param list<int> $components the code points a ligature stands for, in order; empty for a
     *                              glyph that stands for $codePoint alone
     * @param float     $xOffset    how far the glyph is drawn right of its position
     * @param float     $yOffset    how far the glyph is drawn above the baseline
     */
    public function __construct(
        public int $id,
        public int $codePoint,
        public int $advance,
        /** Adjustment applied after this glyph (negative pulls the next glyph closer). */
        public float $kerning = 0.0,
        public array $components = [],
        public float $xOffset = 0.0,
        public float $yOffset = 0.0,
    ) {}

    /**
     * The code points the glyph stands for: several for a ligature.
     *
     * @return list<int>
     */
    public function codePoints(): array
    {
        return [] === $this->components ? [$this->codePoint] : $this->components;
    }

    public function isSpace(): bool
    {
        return 0x20 === $this->codePoint || 0xA0 === $this->codePoint;
    }

    /** How many characters the glyph stands for. */
    public function length(): int
    {
        return [] === $this->components ? 1 : count($this->components);
    }

    /** The text the glyph stands for, as UTF-8. */
    public function text(): string
    {
        $text = '';
        foreach ($this->codePoints() as $codePoint) {
            $text .= mb_chr($codePoint, encoding: 'UTF-8');
        }

        return $text;
    }

    public function withKerning(float $kerning): self
    {
        return new self(
            $this->id,
            $this->codePoint,
            $this->advance,
            $kerning,
            $this->components,
            $this->xOffset,
            $this->yOffset,
        );
    }

    public function withOffset(float $xOffset, float $yOffset): self
    {
        return new self(
            $this->id,
            $this->codePoint,
            $this->advance,
            $this->kerning,
            $this->components,
            $xOffset,
            $yOffset,
        );
    }
}
