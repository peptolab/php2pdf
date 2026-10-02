<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

/**
 * What a document asks of its fonts: what to do with characters a font has no glyph for, and with
 * text in a script the built-in shaper does not shape; the shaper for such scripts; and the
 * conformance level, if any, that requires every font to be embedded.
 *
 * @internal
 */
final readonly class FontOptions
{
    /** @param string|null $embeddingRequiredBy the conformance level that refuses standard fonts, such as "PDF/X-4" */
    public function __construct(
        public GlyphCheck $glyphCheck = GlyphCheck::Warn,
        public ScriptCheck $scriptCheck = ScriptCheck::Error,
        public ?ExternalShaperInterface $complexShaper = null,
        public ?string $embeddingRequiredBy = null,
    ) {}
}
