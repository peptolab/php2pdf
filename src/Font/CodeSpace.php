<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use function bin2hex;
use function chr;
use function implode;
use function intdiv;
use function sprintf;
use function strtoupper;

/**
 * The codes an embedded font's glyphs are written with: one byte for the first 191 CIDs and two
 * for the next 16,384, so most text takes one byte a glyph. CID 1 is the font's space, written as
 * code 32 so that word spacing (Tw) applies to it; the other CIDs below 191 are written as codes
 * of their own number, skipping 32. The font's encoding is a CMap mapping these codes to CIDs,
 * shared by the fonts of a document that need as many, and its ToUnicode map uses the same code
 * space. A font with more glyphs than the codes reach keeps two-byte codes and Identity-H. Codes
 * are never longer than two bytes: macOS's renderer draws the wrong glyphs for three-byte codes.
 *
 * @internal
 */
final class CodeSpace
{
    /** The CMap's name, written in its dictionary as well. */
    public const string NAME = 'Php2Pdf-Compact-H';

    /** How many glyphs the codes reach. */
    public const int CODES = self::ONE_BYTE + (64 * 256);

    /** The CID of the space, written as code 32. */
    public const int SPACE = 1;

    /** CIDs below this are one byte, 00 to BF; the rest two, C000 to FFFF. */
    private const int ONE_BYTE = 191;

    /** The code space: one byte 00 to BF, two bytes C000 to FFFF. */
    private const string RANGES = "2 begincodespacerange\n<00> <BF>\n<C000> <FFFF>\nendcodespacerange\n";

    /** The bytes a CID is written with. */
    public static function bytes(int $cid): string
    {
        if (self::SPACE === $cid) {
            return ' ';
        }
        if ($cid < 32) {
            return chr($cid);
        }
        if ($cid < self::ONE_BYTE) {
            return chr($cid + 1);
        }
        $offset = $cid - self::ONE_BYTE;

        return chr(0xC0 + ($offset >> 8)) . chr($offset & 0xFF);
    }

    /**
     * The encoding CMap: each one-byte code, and each two-byte code with one of the first $leads
     * lead bytes, to the CID bytes() writes with it. Code 1 is not written, and maps to the space.
     */
    public static function cmap(int $leads): string
    {
        $ranges = ['<00> <1F> 0', '<20> <20> ' . self::SPACE, '<21> <BF> 32'];
        for ($lead = 0xC0; $lead < (0xC0 + $leads); ++$lead) {
            $ranges[] = sprintf('<%02X00> <%02XFF> %d', $lead, $lead, self::ONE_BYTE + (($lead - 0xC0) * 256));
        }

        return (
            "/CIDInit /ProcSet findresource begin\n"
                . "12 dict begin\n"
                . "begincmap\n"
                . "/CIDSystemInfo <</Registry (Adobe) /Ordering (Identity) /Supplement 0>> def\n"
                . '/CMapName /'
                . self::NAME
                . " def\n"
                . "/CMapType 1 def\n"
                . self::RANGES
                . (3 + $leads)
                . " begincidrange\n"
                . implode("\n", $ranges)
                . "\nendcidrange\n"
                . "endcmap\n"
                . "CMapName currentdict /CMap defineresource pop\n"
                . "end\n"
                . "end\n"
        );
    }

    /** The code space ranges, as a CMap writes them. */
    public static function codeSpaceRanges(): string
    {
        return self::RANGES;
    }

    /** Whether every glyph of a font with this many has a code. */
    public static function fits(int $glyphs): bool
    {
        return $glyphs <= self::CODES;
    }

    /** A CID's code as the hexadecimal digits of its bytes. */
    public static function hex(int $cid): string
    {
        return strtoupper(bin2hex(self::bytes($cid)));
    }

    /** How many lead bytes the two-byte codes of a font using this many glyphs need. */
    public static function leads(int $glyphs): int
    {
        return $glyphs <= self::ONE_BYTE ? 0 : intdiv($glyphs - self::ONE_BYTE + 255, num2: 256);
    }
}
