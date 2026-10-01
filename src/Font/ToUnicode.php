<?php

declare(strict_types=1);

namespace PhpPdf\Font;

use function array_chunk;
use function count;
use function implode;
use function ksort;
use function sprintf;

/**
 * Builds a ToUnicode CMap mapping 2-byte glyph ids to UTF-16BE text, so copied text is right.
 *
 * @internal
 */
final class ToUnicode
{
    /**
     * @param array<int, int> $glyphToCodePoint glyph id (or code) => code point
     * @param int             $bytes            the length of the codes: 2 for glyph ids, 1 for simple fonts
     */
    public static function build(array $glyphToCodePoint, int $bytes = 2): string
    {
        $format = 1 === $bytes ? '<%02X> <%s>' : '<%04X> <%s>';
        ksort($glyphToCodePoint);
        $entries = [];
        foreach ($glyphToCodePoint as $glyph => $codePoint) {
            $entries[] = sprintf($format, $glyph, self::utf16Hex($codePoint));
        }

        $blocks = '';
        foreach (array_chunk($entries, length: 100) as $chunk) {
            $count  = count($chunk);
            $lines  = implode("\n", $chunk);
            $blocks .= "{$count} beginbfchar\n{$lines}\nendbfchar\n";
        }

        return (
            "/CIDInit /ProcSet findresource begin\n"
                . "12 dict begin\n"
                . "begincmap\n"
                . "/CIDSystemInfo <</Registry (Adobe) /Ordering (UCS) /Supplement 0>> def\n"
                . "/CMapName /Adobe-Identity-UCS def\n"
                . "/CMapType 2 def\n"
                . "1 begincodespacerange\n"
                . (1 === $bytes ? '<00> <FF>' : '<0000> <FFFF>')
                . "\nendcodespacerange\n"
                . "{$blocks}endcmap\n"
                . "CMapName currentdict /CMap defineresource pop\n"
                . "end\nend\n"
        );
    }

    private static function utf16Hex(int $codePoint): string
    {
        if ($codePoint < 0x1_0000) {
            return sprintf('%04X', $codePoint);
        }
        $v = $codePoint - 0x1_0000;

        return sprintf('%04X%04X', 0xD800 | ($v >> 10), 0xDC00 | ($v & 0x3FF));
    }
}
