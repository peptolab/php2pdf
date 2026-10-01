<?php

declare(strict_types=1);

namespace PhpPdf\Core;

/**
 * Encodes PHP (UTF-8) text for PDF text strings: PDFDocEncoding when the text is plain ASCII,
 * otherwise UTF-16BE with a byte order mark.
 *
 * @internal
 */
final class TextString
{
    public static function encode(string $utf8): string
    {
        if (preg_match('/[^\x20-\x7E]/', $utf8) !== 1) {
            return $utf8;
        }

        return "\xFE\xFF" . mb_convert_encoding($utf8, 'UTF-16BE', 'UTF-8');
    }

    /** Formats a date as a PDF date string: D:YYYYMMDDHHmmSSOHH'mm'. */
    public static function date(\DateTimeImmutable $date): string
    {
        $offset = $date->format('P');
        $tz     = '+00:00' === $offset ? 'Z' : $offset[0] . substr($offset, 1, 2) . "'" . substr($offset, 4, 2) . "'";

        return 'D:' . $date->format('YmdHis') . $tz;
    }
}
