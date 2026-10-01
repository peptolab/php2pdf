<?php

declare(strict_types=1);

namespace PhpPdf\Font;

/**
 * Options for showing a line of text. Spacing values are in points, tracking in thousandths of
 * an em, horizontal scaling in percent. Kerning and the font's standard ligatures are on by
 * default.
 *
 * @api
 */
final readonly class TextOptions
{
    public function __construct(
        public float $charSpacing = 0.0,
        public float $wordSpacing = 0.0,
        public float $tracking = 0.0,
        public float $horizontalScale = 100.0,
        public float $rise = 0.0,
        public TextRenderMode $renderMode = TextRenderMode::Fill,
        public bool $kerning = true,
        public bool $ligatures = true,
    ) {}

    public static function default(): self
    {
        static $default = null;

        return $default ??= new self();
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
        );
    }
}
