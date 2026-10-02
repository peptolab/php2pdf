<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use function preg_match;
use function str_starts_with;
use function substr;
use function trim;

/**
 * References to elements of the same document: url(#id) in a property, and #id in an href.
 * Anything else names another file, which is not followed.
 *
 * @internal
 */
final class ElementRef
{
    private const string URL = '/^url\(\s*["\']?#([^"\')]+)["\']?\s*\)\s*(.*)$/s';

    private function __construct() {}

    /** The id in an href of the form #id, or null. */
    public static function fromHref(?string $href): ?string
    {
        $href = trim($href ?? '');
        if (! str_starts_with($href, '#')) {
            return null;
        }
        $id = substr($href, offset: 1);

        return '' === $id ? null : $id;
    }

    /** The id in a property value of the form url(#id), or null. */
    public static function fromUrl(?string $value): ?string
    {
        return self::splitUrl($value ?? '')[0] ?? null;
    }

    /**
     * The id in a value starting url(#id), and what follows it (a paint's fallback colour).
     *
     * @return array{0: string, 1: string}|null
     */
    public static function splitUrl(string $value): ?array
    {
        $m = [];
        if (preg_match(self::URL, trim($value), $m) !== 1) {
            return null;
        }

        return [$m[1] ?? '', trim($m[2] ?? '')];
    }
}
