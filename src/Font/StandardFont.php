<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use function preg_replace;
use function strtolower;

/**
 * The standard 14 fonts every PDF reader provides. They are referenced by name rather than
 * embedded, so they add nothing to the file size, but they only cover WinAnsiEncoding (Symbol
 * and ZapfDingbats have their own character sets) and PDF/X does not allow them.
 *
 * @api
 */
enum StandardFont: string
{
    case Courier              = 'Courier';
    case CourierBold          = 'Courier-Bold';
    case CourierOblique       = 'Courier-Oblique';
    case CourierBoldOblique   = 'Courier-BoldOblique';
    case Helvetica            = 'Helvetica';
    case HelveticaBold        = 'Helvetica-Bold';
    case HelveticaOblique     = 'Helvetica-Oblique';
    case HelveticaBoldOblique = 'Helvetica-BoldOblique';
    case TimesRoman           = 'Times-Roman';
    case TimesBold            = 'Times-Bold';
    case TimesItalic          = 'Times-Italic';
    case TimesBoldItalic      = 'Times-BoldItalic';
    case Symbol               = 'Symbol';
    case ZapfDingbats         = 'ZapfDingbats';

    /**
     * The standard font with this PostScript name, matched ignoring case, spaces, hyphens and
     * underscores, so "helvetica bold" finds Helvetica-Bold.
     */
    public static function fromName(string $name): ?self
    {
        $wanted = self::normalise($name);
        foreach (self::cases() as $case) {
            if (self::normalise($case->value) === $wanted) {
                return $case;
            }
        }

        return null;
    }

    private static function normalise(string $name): string
    {
        return strtolower(preg_replace('/[\s_-]+/', replacement: '', subject: $name) ?? $name);
    }

    /** Whether the font has its own character set instead of WinAnsiEncoding. */
    public function isSymbolic(): bool
    {
        return self::Symbol === $this || self::ZapfDingbats === $this;
    }
}
