<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use Php2Pdf\Font\OpenType\OpenTypeFont;

/**
 * A text shaper outside the library, such as HarfBuzz, for the scripts the built-in engine does
 * not shape (Indic and South East Asian scripts, Tibetan, Mongolian, conjoining Hangul). Set one
 * with DocumentOptions::$complexShaper; see HarfBuzz\HarfBuzz::detect().
 *
 * @api
 */
interface ExternalShaperInterface
{
    /**
     * Shapes a run of one script and direction with the shaper's default features.
     *
     * @param string       $script   the ISO 15924 code of the script, in lower case ("deva")
     * @param list<string> $features features to change, in HarfBuzz's syntax ("smcp", "-liga")
     * @param string|null  $language the text's language as a BCP 47 tag ("tr"), for the font's
     *                               forms for it
     *
     * @return list<array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}> per glyph, in visual
     *         order: glyph id, cluster (the byte offset in $text of the first character the glyph
     *         stands for), x advance, y advance, x offset and y offset, in font units
     */
    public function shape(
        OpenTypeFont $font,
        string $text,
        string $script,
        TextDirection $direction,
        array $features,
        ?string $language = null,
    ): array;
}
