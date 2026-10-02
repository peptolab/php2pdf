<?php

declare(strict_types=1);

namespace Php2Pdf\Core\Filter;

use Php2Pdf\Exception\ParseException;

use function ctype_xdigit;
use function hex2bin;
use function preg_replace;
use function strlen;
use function strpos;
use function substr;

/** @internal */
final class AsciiHex
{
    /** @throws ParseException */
    public static function decode(string $data): string
    {
        $end = strpos($data, needle: '>');
        if (false !== $end) {
            $data = substr($data, offset: 0, length: $end);
        }
        $hex = preg_replace('/\s+/', replacement: '', subject: $data) ?? '';
        if (! ctype_xdigit($hex) && '' !== $hex) {
            throw new ParseException('Invalid ASCIIHex data.');
        }
        if (0 !== (strlen($hex) % 2)) {
            $hex .= '0';
        }

        return (string) hex2bin($hex);
    }
}
