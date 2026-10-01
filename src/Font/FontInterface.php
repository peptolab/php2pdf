<?php

declare(strict_types=1);

namespace PhpPdf\Font;

/**
 * A font text can be shaped, measured and shown with: an embedded font program (Font) or one of
 * the standard 14 fonts every PDF reader has (CoreFont).
 *
 * Metrics are returned in points for a given size; advances are in thousandths of an em. Get
 * fonts from the document's FontRegistry (load(), find(), standard()): text is drawn only with
 * the library's own fonts, which also know how to embed themselves.
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

    /** Whether the font has a glyph for a Unicode code point. */
    public function hasGlyph(int $codePoint): bool;

    public function lineGap(float $size): float;

    /** The PostScript name. */
    public function name(): string;

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
