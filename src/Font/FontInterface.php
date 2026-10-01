<?php

declare(strict_types=1);

namespace PhpPdf\Font;

use PhpPdf\Core\Object\Reference;
use PhpPdf\Document\Document;

/**
 * A font text can be shaped, measured and shown with: an embedded font program (Font) or one of
 * the standard 14 fonts every PDF reader has (CoreFont).
 *
 * Metrics are returned in points for a given size; advances are in thousandths of an em.
 *
 * @api
 */
interface FontInterface
{
    /** Advance of a glyph in thousandths of an em, as the PDF will carry it. */
    public function advance(int $glyphId): int;

    public function ascender(float $size): float;

    public function capHeight(float $size): float;

    /** Negative: distance below the baseline. */
    public function descender(float $size): float;

    /**
     * The document the font is embedded in.
     *
     * @internal
     */
    public function document(): Document;

    /**
     * The bytes that select these glyphs in a content stream string.
     *
     * @internal
     *
     * @param list<int> $glyphIds
     */
    public function encode(array $glyphIds): string;

    /** Whether the font has a glyph for a Unicode code point. */
    public function hasGlyph(int $codePoint): bool;

    public function lineGap(float $size): float;

    /**
     * Records the glyphs of a run as used.
     *
     * @internal
     */
    public function markUsed(GlyphRun $run): void;

    /** The PostScript name. */
    public function name(): string;

    /** @internal */
    public function reference(): Reference;

    /** Maps UTF-8 text to glyphs with kerning. */
    public function shape(string $text, ?TextOptions $options = null): GlyphRun;

    public function strikeoutPosition(float $size): float;

    public function strikeoutSize(float $size): float;

    /** Underline offset from the baseline (negative is below). */
    public function underlinePosition(float $size): float;

    public function underlineThickness(float $size): float;

    /** Width of text in points at the given size, including kerning, spacing and tracking. */
    public function width(string|GlyphRun $text, float $size, ?TextOptions $options = null): float;

    public function xHeight(float $size): float;
}
