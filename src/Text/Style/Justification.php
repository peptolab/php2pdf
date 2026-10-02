<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Style;

use Php2Pdf\Exception\InvalidArgumentException;

use function sprintf;

/**
 * How far justification may change a style's text, as in InDesign's Justification settings: its
 * word spaces, as percentages of their width (down to the minimum when a line is set tight, up to
 * the maximum when it is set loose); the space between its letters, as percentages of a word
 * space; and the width of its glyphs, as percentages of their own. The paragraph composer weighs
 * breaks by all three. A justified line takes its slack in word spaces first, within their limits,
 * then between letters, then in the width of its glyphs; what is left goes to the word spaces past
 * their maximum. By default letters and glyphs do not change.
 *
 * @api
 */
final readonly class Justification
{
    /** The limits a style without its own justification has. */
    public const float MIN_WORD_SPACING = 80.0;
    public const float MAX_WORD_SPACING = 133.0;

    /**
     * @param float $minWordSpacing   the narrowest a word space may become, at most 100
     * @param float $maxWordSpacing   the widest a word space should become, at least 100
     * @param float $minLetterSpacing the most letters may close up, as a percentage of a word
     *                                space: 0 or less, above -100
     * @param float $maxLetterSpacing the most letters may open up, as a percentage of a word
     *                                space: 0 or more
     * @param float $minGlyphScaling  the narrowest glyphs may become, as a percentage of their
     *                                width: at most 100, above 0
     * @param float $maxGlyphScaling  the widest glyphs may become: at least 100
     *
     * @throws InvalidArgumentException for limits that do not bracket the unchanged value
     */
    public function __construct(
        public float $minWordSpacing = self::MIN_WORD_SPACING,
        public float $maxWordSpacing = self::MAX_WORD_SPACING,
        public float $minLetterSpacing = 0.0,
        public float $maxLetterSpacing = 0.0,
        public float $minGlyphScaling = 100.0,
        public float $maxGlyphScaling = 100.0,
    ) {
        if ($minWordSpacing <= 0.0 || $minWordSpacing > 100.0 || $maxWordSpacing < 100.0) {
            throw new InvalidArgumentException(sprintf(
                'Word spacing limits must bracket 100%%, with a minimum above 0, not %s%% to %s%%.',
                $minWordSpacing,
                $maxWordSpacing,
            ));
        }
        if ($minLetterSpacing <= -100.0 || $minLetterSpacing > 0.0 || $maxLetterSpacing < 0.0) {
            throw new InvalidArgumentException(sprintf(
                'Letter spacing limits must bracket 0%%, with a minimum above -100%%, not %s%% to %s%%.',
                $minLetterSpacing,
                $maxLetterSpacing,
            ));
        }
        if ($minGlyphScaling <= 0.0 || $minGlyphScaling > 100.0 || $maxGlyphScaling < 100.0) {
            throw new InvalidArgumentException(sprintf(
                'Glyph scaling limits must bracket 100%%, with a minimum above 0, not %s%% to %s%%.',
                $minGlyphScaling,
                $maxGlyphScaling,
            ));
        }
    }

    /** How far a word space of a style with this justification, or the default, may shrink. */
    public static function shrinkOf(?self $justification): float
    {
        return null === $justification ? (100.0 - self::MIN_WORD_SPACING) / 100.0 : $justification->shrink();
    }

    /** How far a word space of a style with this justification, or the default, may stretch. */
    public static function stretchOf(?self $justification): float
    {
        return null === $justification ? (self::MAX_WORD_SPACING - 100.0) / 100.0 : $justification->stretch();
    }

    /** How far glyphs may become narrower, as a fraction of their width. */
    public function glyphShrink(): float
    {
        return (100.0 - $this->minGlyphScaling) / 100.0;
    }

    /** How far glyphs may become wider, as a fraction of their width. */
    public function glyphStretch(): float
    {
        return ($this->maxGlyphScaling - 100.0) / 100.0;
    }

    /** How far the space after a letter may close up, as a fraction of a word space. */
    public function letterShrink(): float
    {
        return -$this->minLetterSpacing / 100.0;
    }

    /** How far the space after a letter may open up, as a fraction of a word space. */
    public function letterStretch(): float
    {
        return $this->maxLetterSpacing / 100.0;
    }

    /** How far a word space may shrink, as a fraction of its width. */
    public function shrink(): float
    {
        return (100.0 - $this->minWordSpacing) / 100.0;
    }

    /** How far a word space may stretch, as a fraction of its width. */
    public function stretch(): float
    {
        return ($this->maxWordSpacing - 100.0) / 100.0;
    }
}
