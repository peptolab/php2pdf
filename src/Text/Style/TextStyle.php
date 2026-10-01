<?php

declare(strict_types=1);

namespace PhpPdf\Text\Style;

use PhpPdf\Color\ColorInterface;
use PhpPdf\Font\FontInterface;
use PhpPdf\Font\TextOptions;
use PhpPdf\Font\TextRenderMode;

use function get_object_vars;

/**
 * Character and paragraph formatting, as in a desktop publishing style sheet. Every property is
 * optional so styles can be layered: a null property inherits from the style below it. The font
 * may be a FontInterface or a name resolved through the document's font registry when the style is used.
 * Fallback fonts set characters the font lacks, each from the first fallback that has it.
 *
 * @api
 */
final readonly class TextStyle
{
    /**
     * @param list<TabStop>|null $tabs
     * @param int|null           $orphans minimum lines kept at the bottom of a box
     * @param int|null           $widows  minimum lines carried to the next box
     * @param list<FontInterface|string>|null $fallback fonts tried in order for characters the
     *                                                  font lacks
     */
    public function __construct(
        public FontInterface|string|null $font = null,
        public ?float $size = null,
        public ?Leading $leading = null,
        public ?ColorInterface $color = null,
        public ?float $tracking = null,
        public ?float $charSpacing = null,
        public ?float $wordSpacing = null,
        public ?float $horizontalScale = null,
        public ?float $rise = null,
        public ?TextRenderMode $renderMode = null,
        public ?bool $underline = null,
        public ?bool $strikeout = null,
        public ?bool $kerning = null,
        public FontInterface|string|null $bold = null,
        public FontInterface|string|null $italic = null,
        public FontInterface|string|null $boldItalic = null,
        public ?Alignment $alignment = null,
        public ?Alignment $lastLineAlignment = null,
        public ?float $firstLineIndent = null,
        public ?float $leftIndent = null,
        public ?float $rightIndent = null,
        public ?float $spaceBefore = null,
        public ?float $spaceAfter = null,
        public ?bool $hyphenate = null,
        public ?string $language = null,
        public ?int $minHyphenatedWordLength = null,
        public ?int $hyphenLeftMin = null,
        public ?int $hyphenRightMin = null,
        public ?array $tabs = null,
        public ?float $defaultTabWidth = null,
        public ?bool $keepWithNext = null,
        public ?bool $keepTogether = null,
        public ?int $orphans = null,
        public ?int $widows = null,
        public ?array $fallback = null,
    ) {}

    public function fontSize(): float
    {
        return $this->size ?? 10.0;
    }

    /** Line spacing in points: the leading, or 120% of the size when unset. */
    public function lineHeight(): float
    {
        return ($this->leading ?? Leading::percent(120.0))->resolve($this->fontSize());
    }

    /** Values of $override replace this style's values where set. */
    public function merge(?self $override): self
    {
        if (null === $override) {
            return $this;
        }
        $base = get_object_vars($this);
        foreach (get_object_vars($override) as $key => $value) {
            if (null === $value) {
                continue;
            }
            $base[$key] = $value;
        }

        /** @var array<string, mixed> $base */
        return new self(...$base);
    }

    /** Multiplies size, leading, indents and spacing, for copy fitting. */
    public function scaled(float $factor): self
    {
        return $this->merge(new self(
            size: null === $this->size ? null : $this->size * $factor,
            leading: null === $this->leading || $this->leading->relative
                ? null
                : Leading::pt($this->leading->value * $factor),
            firstLineIndent: null === $this->firstLineIndent ? null : $this->firstLineIndent * $factor,
            spaceBefore: null === $this->spaceBefore ? null : $this->spaceBefore * $factor,
            spaceAfter: null === $this->spaceAfter ? null : $this->spaceAfter * $factor,
        ));
    }

    /** The shaping and showing options this style implies. */
    public function textOptions(): TextOptions
    {
        return new TextOptions(
            charSpacing: $this->charSpacing ?? 0.0,
            wordSpacing: $this->wordSpacing ?? 0.0,
            tracking: $this->tracking ?? 0.0,
            horizontalScale: $this->horizontalScale ?? 100.0,
            rise: $this->rise ?? 0.0,
            renderMode: $this->renderMode ?? TextRenderMode::Fill,
            kerning: $this->kerning ?? true,
        );
    }
}
