<?php

declare(strict_types=1);

namespace PhpPdf\Font;

use function in_array;

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
     * The features shaping applies: the standard ligatures when they are used, then the others.
     *
     * @return list<string>
     */
    public function activeFeatures(): array
    {
        $features = $this->useLigatures() ? ['liga'] : [];
        foreach ($this->features as $feature) {
            if ('liga' === $feature || in_array($feature, $features, strict: true)) {
                continue;
            }
            $features[] = $feature;
        }

        return $features;
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
