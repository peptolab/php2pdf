<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use WeakMap;

use function array_filter;
use function array_values;
use function in_array;
use function str_starts_with;
use function substr;

/**
 * Options for showing a line of text. Spacing values are in points, tracking in thousandths of
 * an em, horizontal scaling in percent. Kerning and the font's standard ligatures are on by
 * default; other OpenType features are asked for by tag.
 *
 * @api
 */
final readonly class TextOptions
{
    /**
     * @param list<string>  $features  OpenType features to apply besides the standard ligatures:
     *                                 tnum, onum, smcp, c2sc, case, frac, sups and so on
     * @param TextDirection $direction the direction of the line, whose text in either direction is
     *                                 ordered by the Unicode bidi algorithm; a font shapes a run
     *                                 right to left when it is RightToLeft
     * @param string|null   $language  the text's language as a BCP 47 tag ("tr", "nl-BE"): the
     *                                 font's forms for it are used where it has them
     */
    public function __construct(
        public float $charSpacing = 0.0,
        public float $wordSpacing = 0.0,
        public float $tracking = 0.0,
        public float $horizontalScale = 100.0,
        public float $rise = 0.0,
        public TextRenderMode $renderMode = TextRenderMode::Fill,
        public bool $kerning = true,
        public bool $ligatures = true,
        public array $features = [],
        public TextDirection $direction = TextDirection::Auto,
        public ?string $language = null,
    ) {}

    public static function default(): self
    {
        static $default = null;

        return $default ??= new self();
    }

    /**
     * The features shaping applies, as HarfBuzz's defaults: glyph composition and decomposition,
     * localised forms, required ligatures, contextual alternates and required contextual
     * alternates, the standard and contextual ligatures when ligatures are used, then the features
     * asked for. A feature asked for as '-tag' is left out.
     *
     * @return list<string>
     */
    public function activeFeatures(): array
    {
        // Text is shaped with the same options many times over, so they are worked out once for
        // each, and held outside it so that it is still equal to options made the same way.
        /** @var WeakMap<self, list<string>> $active */
        static $active = new WeakMap();

        return $active[$this] ??= $this->features();
    }

    /**
     * Whether standard ligatures apply: when asked for and no tracking or character spacing
     * opens the text up, as in InDesign.
     */
    public function useLigatures(): bool
    {
        return $this->ligatures && 0.0 === $this->tracking && 0.0 === $this->charSpacing;
    }

    /** The same options for text in a direction. */
    public function withDirection(TextDirection $direction): self
    {
        return new self(
            $this->charSpacing,
            $this->wordSpacing,
            $this->tracking,
            $this->horizontalScale,
            $this->rise,
            $this->renderMode,
            $this->kerning,
            $this->ligatures,
            $this->features,
            $direction,
            $this->language,
        );
    }

    /** The same options at another horizontal scale, in percent. */
    public function withHorizontalScale(float $horizontalScale): self
    {
        return new self(
            $this->charSpacing,
            $this->wordSpacing,
            $this->tracking,
            $horizontalScale,
            $this->rise,
            $this->renderMode,
            $this->kerning,
            $this->ligatures,
            $this->features,
            $this->direction,
            $this->language,
        );
    }

    /** The same options with ligatures on or off. */
    public function withLigatures(bool $ligatures): self
    {
        return new self(
            $this->charSpacing,
            $this->wordSpacing,
            $this->tracking,
            $this->horizontalScale,
            $this->rise,
            $this->renderMode,
            $this->kerning,
            $ligatures,
            $this->features,
            $this->direction,
            $this->language,
        );
    }

    /** @return list<string> */
    private function features(): array
    {
        $features = ['ccmp', 'locl', 'rlig', 'calt', 'rclt', ...($this->useLigatures() ? ['liga', 'clig'] : [])];
        $off      = [];
        foreach ($this->features as $feature) {
            if (str_starts_with($feature, '-')) {
                $off[] = substr($feature, offset: 1);
                continue;
            }
            if ('liga' === $feature || in_array($feature, $features, strict: true)) {
                continue;
            }
            $features[] = $feature;
        }

        return array_values(array_filter(
            $features,
            static fn(string $tag): bool => ! in_array($tag, $off, strict: true),
        ));
    }
}
