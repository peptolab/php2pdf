<?php

declare(strict_types=1);

namespace Php2Pdf\Core;

/**
 * A line end in UTF-8 text, as a regular expression fragment matched byte by byte: CRLF, LF,
 * vertical tab, form feed or CR, and next line (U+0085), line separator (U+2028) and paragraph
 * separator (U+2029) in their UTF-8 form. These are the line ends \R matches in UTF-8 mode, but
 * written out: \R without the u flag also matches the byte 0x85, which ends the UTF-8 form of
 * characters such as Å and х, and with it a pattern fails outright on text that is not valid UTF-8.
 *
 * @internal
 */
final class LineEnd
{
    public const string PATTERN = '(?:\r\n|[\n\x0B\f\r]|\xC2\x85|\xE2\x80[\xA8\xA9])';
}
