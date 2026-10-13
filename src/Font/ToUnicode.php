<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use Closure;

use function array_chunk;
use function count;
use function implode;
use function ksort;
use function sprintf;

/**
 * Builds a ToUnicode CMap mapping a font's codes to UTF-16BE text, so copied text is right; a
 * ligature maps to all the characters it stands for.
 *
 * @internal
 */
final class ToUnicode
{
    /**
     * For a simple font: one-byte codes.
     *
     * @param array<int, int|list<int>> $codeToCodePoint code => code point, or the code points of a ligature
     */
    public static function build(array $codeToCodePoint): string
    {
        return self::cmap(
            $codeToCodePoint,
            static fn(int $code): string => sprintf('%02X', $code),
            "1 begincodespacerange\n<00> <FF>\nendcodespacerange\n",
        );
    }

    /**
     * For an embedded font: codes written in its CodeSpace.
     *
     * @param array<int, int|list<int>> $codeToCodePoint code => code point, or the code points of a ligature
     */
    public static function compact(array $codeToCodePoint): string
    {
        return self::cmap($codeToCodePoint, CodeSpace::hex(...), CodeSpace::codeSpaceRanges());
    }

    /**
     * For an embedded font with more glyphs than the compact codes reach: two-byte codes.
     *
     * @param array<int, int|list<int>> $codeToCodePoint code => code point, or the code points of a ligature
     */
    public static function twoByte(array $codeToCodePoint): string
    {
        return self::cmap(
            $codeToCodePoint,
            static fn(int $code): string => sprintf('%04X', $code),
            "1 begincodespacerange\n<0000> <FFFF>\nendcodespacerange\n",
        );
    }

    /**
     * @param array<int, int|list<int>> $codeToCodePoint
     * @param Closure(int): string      $hex             a code as hexadecimal digits
     */
    private static function cmap(array $codeToCodePoint, Closure $hex, string $codeSpace): string
    {
        ksort($codeToCodePoint);
        $entries = [];
        foreach ($codeToCodePoint as $code => $codePoint) {
            $text = '';
            foreach ((array) $codePoint as $point) {
                $text .= self::utf16Hex($point);
            }
            $entries[] = "<{$hex($code)}> <{$text}>";
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
                . $codeSpace
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
