<?php

declare(strict_types=1);

namespace Php2Pdf\Text;

use function str_replace;

/**
 * Helpers for building Textflow markup from application data.
 *
 * @api
 */
final class Markup
{
    /**
     * Escapes text so markup parsing gives it back exactly: &, < and > become entities, so a
     * name like "Tom <Jerry>" or "R&amp;D" is not read as a tag or an entity.
     */
    public static function escape(string $text): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
    }
}
