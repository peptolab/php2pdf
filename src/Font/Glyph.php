<?php

declare(strict_types=1);

namespace PhpPdf\Font;

use function count;
use function mb_chr;

/**
 * One positioned glyph in a shaped run. Advance and kerning are in thousandths of an em.
 *
 * @api
 */
final readonly class Glyph
{
    /**
     * @param list<int> $components the code points a ligature stands for, in order; empty for a
     *                              glyph that stands for $codePoint alone
     */
    public function __construct(
        public int $id,
        public int $codePoint,
        public int $advance,
        /** Adjustment applied after this glyph (negative pulls the next glyph closer). */
        public float $kerning = 0.0,
        public array $components = [],
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
        return new self($this->id, $this->codePoint, $this->advance, $kerning, $this->components);
    }
}
