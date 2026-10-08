<?php

declare(strict_types=1);

namespace Php2Pdf\Core;

use DateTimeImmutable;
use Php2Pdf\Exception\InvalidArgumentException;

use function mb_check_encoding;
use function mb_convert_encoding;
use function preg_match;
use function substr;

/**
 * Encodes PHP (UTF-8) text for PDF text strings: PDFDocEncoding when the text is plain ASCII,
 * otherwise UTF-16BE with a byte order mark.
 *
 * @internal
 */
final class TextString
{
    /** Formats a date as a PDF date string: D:YYYYMMDDHHmmSSOHH'mm'. */
    public static function date(DateTimeImmutable $date): string
    {
        $offset = $date->format('P');
        $tz     = '+00:00' === $offset
            ? 'Z'
            : $offset[0] . substr($offset, offset: 1, length: 2) . "'" . substr($offset, offset: 4, length: 2) . "'";
        $stamp = $date->format('YmdHis');

        return "D:{$stamp}{$tz}";
    }

    public static function encode(string $utf8): string
    {
        if (preg_match('/[^\x20-\x7E]/', $utf8) !== 1) {
            return $utf8;
        }
        // mb_convert_encoding() turns ill-formed bytes into "?" rather than failing.
        if (! mb_check_encoding($utf8, encoding: 'UTF-8')) {
            throw new InvalidArgumentException('Text is not valid UTF-8.');
        }
        $utf16 = (string) mb_convert_encoding($utf8, to_encoding: 'UTF-16BE', from_encoding: 'UTF-8');

        return "\xFE\xFF{$utf16}";
    }
}
