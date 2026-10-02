<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

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
     * @param list<string> $features OpenType features to apply besides the standard ligatures:
     *                               tnum, onum, smcp, c2sc, case, frac, sups and so on
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

    /**
     * Whether standard ligatures apply: when asked for and no tracking or character spacing
     * opens the text up, as in InDesign.
     */
    public function useLigatures(): bool
    {
        return $this->ligatures && 0.0 === $this->tracking && 0.0 === $this->charSpacing;
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
        );
    }
}
