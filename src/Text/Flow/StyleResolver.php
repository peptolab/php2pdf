<?php

declare(strict_types=1);

namespace PhpPdf\Text\Flow;

use PhpPdf\Color\Gray;
use PhpPdf\Document\Document;
use PhpPdf\Exception\PdfException;
use PhpPdf\Font\FontInterface;
use PhpPdf\Text\Style\Alignment;
use PhpPdf\Text\Style\Leading;
use PhpPdf\Text\Style\TextStyle;

use function is_string;

/**
 * Turns layered styles into complete ones: fonts named as strings are looked up in the document's
 * registry and every unset property gets its default.
 *
 * @internal
 */
final class StyleResolver
{
    private readonly TextStyle $defaults;

    public function __construct(
        private readonly Document $document,
    ) {
        $this->defaults = new TextStyle(
            size: 10.0,
            leading: Leading::percent(120.0),
            color: Gray::black(),
            tracking: 0.0,
            charSpacing: 0.0,
            wordSpacing: 0.0,
            horizontalScale: 100.0,
            rise: 0.0,
            underline: false,
            strikeout: false,
            kerning: true,
            alignment: Alignment::Left,
            firstLineIndent: 0.0,
            leftIndent: 0.0,
            rightIndent: 0.0,
            spaceBefore: 0.0,
            spaceAfter: 0.0,
            hyphenate: false,
            language: 'en',
            minHyphenatedWordLength: 6,
            hyphenLeftMin: 2,
            hyphenRightMin: 2,
            tabs: [],
            defaultTabWidth: 36.0,
            keepWithNext: false,
            keepTogether: false,
            orphans: 2,
            widows: 2,
        );
    }

    /** The resolved style's font, which resolve() guarantees. */
    public static function fontOf(TextStyle $style): FontInterface
    {
        $font = $style->font;
        if (! $font instanceof FontInterface) {
            throw new PdfException('Text styles need a font before text can be laid out.');
        }

        return $font;
    }

    /** A style by name from the registry, or the style itself. */
    public function named(TextStyle|string|null $style): ?TextStyle
    {
        return is_string($style) ? $this->document->styles()->get($style) : $style;
    }

    /** Layers $style over $base and the defaults, resolving fonts. */
    public function resolve(?TextStyle $base, TextStyle|string|null $style = null): TextStyle
    {
        $merged = $this->defaults->merge($base)->merge($this->named($style));

        return $merged->merge(new TextStyle(
            font: $this->font($merged->font),
            bold: $this->font($merged->bold),
            italic: $this->font($merged->italic),
            boldItalic: $this->font($merged->boldItalic),
            fallback: null === $merged->fallback ? null : $this->fallbacks($merged->fallback),
        ));
    }

    /**
     * The fonts of a fallback list, resolved.
     *
     * @param list<FontInterface|string> $fonts
     *
     * @return list<FontInterface>
     */
    private function fallbacks(array $fonts): array
    {
        $resolved = [];
        foreach ($fonts as $font) {
            $resolved[] = is_string($font) ? $this->document->fonts()->find($font) : $font;
        }

        return $resolved;
    }

    private function font(FontInterface|string|null $font): ?FontInterface
    {
        return is_string($font) ? $this->document->fonts()->find($font) : $font;
    }
}
