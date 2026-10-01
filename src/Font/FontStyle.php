<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

/** @api */
enum FontStyle
{
    case Regular;
    case Bold;
    case Italic;
    case BoldItalic;

    public function isBold(): bool
    {
        return self::Bold === $this || self::BoldItalic === $this;
    }

    public function isItalic(): bool
    {
        return self::Italic === $this || self::BoldItalic === $this;
    }
}
