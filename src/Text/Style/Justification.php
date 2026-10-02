<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Style;

use Php2Pdf\Exception\InvalidArgumentException;

use function sprintf;

/**
 * How far justification may change a style's word spaces, as percentages of their width, as in
 * InDesign's Justification settings: down to the minimum when a line is set tight, up to the
 * maximum when it is set loose. The paragraph composer weighs breaks by these limits, and a
 * justified line shares its slack among its spaces in proportion to how far each may go.
 *
 * @api
 */
final readonly class Justification
{
    /** The limits a style without its own justification has. */
    public const float MIN_WORD_SPACING = 80.0;
    public const float MAX_WORD_SPACING = 133.0;

    /**
     * @param float $minWordSpacing the narrowest a word space may become, at most 100
     * @param float $maxWordSpacing the widest a word space should become, at least 100
     *
     * @throws InvalidArgumentException for limits that do not bracket 100, or a minimum of 0
     */
    public function __construct(
        public float $minWordSpacing = self::MIN_WORD_SPACING,
        public float $maxWordSpacing = self::MAX_WORD_SPACING,
    ) {
        if ($minWordSpacing <= 0.0 || $minWordSpacing > 100.0 || $maxWordSpacing < 100.0) {
            throw new InvalidArgumentException(sprintf(
                'Word spacing limits must bracket 100%%, with a minimum above 0, not %s%% to %s%%.',
                $minWordSpacing,
                $maxWordSpacing,
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
