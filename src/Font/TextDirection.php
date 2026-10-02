<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

/**
 * The direction of a line or paragraph of text. Auto takes it from the first letter that has a
 * direction, as the Unicode bidi algorithm does; text in both directions is ordered by that
 * algorithm either way.
 *
 * @api
 */
enum TextDirection
{
    case Auto;
    case LeftToRight;
    case RightToLeft;

    /** The bidi paragraph level: 0, 1, or null to take it from the text. */
    public function level(): ?int
    {
        return match ($this) {
            self::Auto => null,
            self::LeftToRight => 0,
            self::RightToLeft => 1,
        };
    }
}
