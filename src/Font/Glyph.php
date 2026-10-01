<?php

declare(strict_types=1);

namespace PhpPdf\Font;

/**
 * One positioned glyph in a shaped run. Advance and kerning are in thousandths of an em.
 *
 * @api
 */
final readonly class Glyph
{
    public function __construct(
        public int $id,
        public int $codePoint,
        public int $advance,
        /** Adjustment applied after this glyph (negative pulls the next glyph closer). */
        public float $kerning = 0.0,
    ) {}

    public function isSpace(): bool
    {
        return 0x20 === $this->codePoint || 0xA0 === $this->codePoint;
    }
}
