<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Canvas\PlaceableInterface;
use Php2Pdf\Color\Gray;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\LayoutException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Font\FontInterface;
use Php2Pdf\Text\Style\Leading;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\TextContext;
use WeakMap;

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

    /** @var WeakMap<TextContext, self>|null the resolver of each text context, so its styles are shared */
    private static ?WeakMap $resolvers = null;

    /**
     * Styles resolved so far, by base and then by the style layered over it, each kept while
     * both are.
     *
     * @var WeakMap<TextStyle, WeakMap<TextStyle, TextStyle>>
     */
    private WeakMap $resolved;

    /** Stands for no base or no style: merging it changes nothing. */
    private readonly TextStyle $none;

    public function __construct(
        private readonly TextContext $context,
    ) {
        $this->resolved = new WeakMap();
        $this->none     = new TextStyle();
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
            ligatures: true,
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
            throw new LayoutException('Text styles need a font before text can be laid out.');
        }

        return $font;
    }

    /** The resolver for a text context, shared by every flow and table made in it. */
    public static function for(TextContext $context): self
    {
        self::$resolvers ??= new WeakMap();

        return self::$resolvers[$context] ??= new self($context);
    }

    /** Artwork defined for markup by name. */
    public function inline(string $name): PlaceableInterface
    {
        return $this->context->inlines->get($name);
    }

    /** A style by name from the registry, or the style itself. */
    public function named(TextStyle|string|null $style): ?TextStyle
    {
        return is_string($style) ? $this->context->styles->get($style) : $style;
    }

    /**
     * Layers $style over $base and the defaults, resolving fonts. The same base and style give the
     * same resolved style, so the cells of a table share a few styles rather than each making its
     * own.
     *
     * @throws IoException
     * @throws ParseException
     */
    public function resolve(?TextStyle $base, TextStyle|string|null $style = null): TextStyle
    {
        $from   = $base ?? $this->none;
        $layer  = $this->named($style) ?? $this->none;
        $byBase = $this->resolved[$from] ?? new WeakMap();
        $done   = $byBase[$layer] ?? null;
        if (null !== $done) {
            return $done;
        }
        $done                  = $this->layer($from, $layer);
        $byBase[$layer]        = $done;
        $this->resolved[$from] = $byBase;

        return $done;
    }

    /**
     * The fonts of a fallback list, resolved.
     *
     * @param list<FontInterface|string> $fonts
     *
     * @return list<FontInterface>
     *
     * @throws IoException
     * @throws ParseException
     */
    private function fallbacks(array $fonts): array
    {
        $resolved = [];
        foreach ($fonts as $font) {
            $resolved[] = is_string($font) ? $this->context->fonts->find($font) : $font;
        }

        return $resolved;
    }

    /**
     * @throws IoException
     * @throws ParseException
     */
    private function font(FontInterface|string|null $font): ?FontInterface
    {
        return is_string($font) ? $this->context->fonts->find($font) : $font;
    }

    /**
     * @throws IoException
     * @throws ParseException
     */
    private function layer(TextStyle $base, TextStyle $style): TextStyle
    {
        $merged = $this->defaults->merge($base)->merge($style);

        return $merged->merge(new TextStyle(
            font: $this->font($merged->font),
            bold: $this->font($merged->bold),
            italic: $this->font($merged->italic),
            boldItalic: $this->font($merged->boldItalic),
            fallback: null === $merged->fallback ? null : $this->fallbacks($merged->fallback),
        ));
    }
}
