<?php

declare(strict_types=1);

namespace PhpPdf\Font;

/**
 * What happens when text uses a character the font has no glyph for. The character is drawn as
 * the font's .notdef glyph (usually a box or nothing) unless the check stops it.
 *
 * @api
 */
enum GlyphCheck
{
    /** Throw a PdfException naming the character and the font. */
    case Error;

    /** Draw .notdef and record a warning in the font registry, once per font and character. */
    case Warn;

    /** Draw .notdef silently. */
    case Ignore;
}
