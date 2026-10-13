<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use Php2Pdf\Exception\ParseException;

use function array_flip;
use function array_key_exists;
use function count;
use function floor;
use function ksort;
use function log;
use function ord;
use function pack;
use function str_repeat;
use function strlen;
use function substr;
use function substr_replace;
use function unpack;

use const SORT_STRING;

/**
 * Builds a TrueType font containing only the requested glyphs, numbered in the order given: the
 * glyph asked for first becomes glyph 0, the next glyph 1, and so on. The components of composite
 * glyphs follow the glyphs asked for, and the composites refer to them by their new numbers.
 *
 * @internal
 */
final class TrueTypeSubsetter
{
    /**
     * @param list<int> $glyphIds the glyphs to keep, in their new order; the first should be
     *                            .notdef (0). An id the font lacks keeps its place as an empty
     *                            glyph.
     *
     * @throws ParseException
     */
    public static function subset(OpenTypeFont $font, array $glyphIds): string
    {
        $order     = self::withComponents($font, $glyphIds);
        $numGlyphs = count($order);
        $numbers   = array_flip($order);

        $glyf = '';
        $loca = '';
        $hmtx = '';
        foreach ($order as $gid) {
            $data = self::renumbered($font->glyphData($gid), $numbers);
            $data .= str_repeat("\0", (4 - (strlen($data) % 4)) % 4);
            $loca .= pack('N', strlen($glyf));
            $glyf .= $data;
            $hmtx .= pack('nn', $font->advance($gid), $font->leftSideBearing($gid) & 0xFFFF);
        }
        $loca .= pack('N', strlen($glyf));

        $head = $font->table('head') ?? '';
        $head = substr_replace($head, replace: "\0\0\0\0", offset: 8, length: 4); // checkSumAdjustment, recomputed below
        $head = substr_replace($head, replace: "\0\x01", offset: 50, length: 2); // indexToLocFormat: long offsets

        $tables = [
            'glyf' => $glyf,
            'head' => $head,
            // Every glyph has a long metric.
            'hhea' => substr_replace($font->table('hhea') ?? '', pack('n', $numGlyphs), offset: 34, length: 2),
            'hmtx' => $hmtx,
            'loca' => $loca,
            'maxp' => substr_replace($font->table('maxp') ?? '', pack('n', $numGlyphs), offset: 4, length: 2),
            'post' => self::postFormat3($font),
        ];
        foreach (['cvt ', 'fpgm', 'prep'] as $tag) {
            $data = $font->table($tag);
            if (null !== $data && '' !== $data) {
                $tables[$tag] = $data;
            }
        }
        ksort($tables, SORT_STRING);

        return self::assemble($tables);
    }

    /** @param array<string, string> $tables tag => data, sorted by tag */
    private static function assemble(array $tables): string
    {
        $count         = count($tables);
        $entrySelector = (int) floor(log($count, base: 2));
        $searchRange   = (2 ** $entrySelector) * 16;
        $directory     = pack('Nnnnn', 0x0001_0000, $count, $searchRange, $entrySelector, ($count * 16) - $searchRange);

        $offset     = 12 + (16 * $count);
        $body       = '';
        $records    = '';
        $headOffset = null;
        $sum        = 0; // the whole font's checksum: its 4-byte aligned parts' checksums add up
        foreach ($tables as $tag => $data) {
            $length   = strlen($data);
            $padded   = $data . str_repeat("\0", (4 - ($length % 4)) % 4);
            $checksum = self::checksum($padded);
            $sum      += $checksum;
            $records  .= $tag . pack('NNN', $checksum, $offset, $length);
            if ('head' === $tag) {
                $headOffset = $offset;
            }
            $body   .= $padded;
            $offset += strlen($padded);
        }

        $font = $directory . $records . $body;
        if (null !== $headOffset) {
            $sum        = ($sum + self::checksum($directory . $records)) & 0xFFFF_FFFF;
            $adjustment = (0xB1B0_AFBA - $sum) & 0xFFFF_FFFF;
            $font       = substr_replace($font, pack('N', $adjustment), $headOffset + 8, length: 4);
        }

        return $font;
    }

    private static function checksum(string $data): int
    {
        $data .= str_repeat("\0", (4 - (strlen($data) % 4)) % 4);
        $sum  = 0;
        foreach (unpack('N*', $data) as $word) {
            $sum = ($sum + (int) $word) & 0xFFFF_FFFF;
        }

        return $sum;
    }

    /** @throws ParseException */
    private static function postFormat3(OpenTypeFont $font): string
    {
        $post = $font->table('post');
        $tail = null !== $post && strlen($post) >= 32
            ? substr($post, offset: 4, length: 28)
            : str_repeat("\0", times: 28);

        return "\0\x03\0\0{$tail}";
    }

    /**
     * A glyph's data with a composite's component glyph ids replaced by their new numbers; a simple
     * or empty glyph as it is.
     *
     * @param array<int, int> $numbers old glyph id => new
     */
    private static function renumbered(string $data, array $numbers): string
    {
        // A composite glyph has a negative contour count, so its first byte has the top bit set.
        if (strlen($data) < 12 || ord($data[0]) < 0x80) {
            return $data;
        }
        $offset = 10;
        do {
            if (($offset + 4) > strlen($data)) {
                break;
            }
            /** @var array{1: int, 2: int} $entry */
            $entry = unpack('n2', $data, $offset);
            [1 => $flags, 2 => $component] = $entry;
            $data   = substr_replace($data, pack('n', $numbers[$component] ?? 0), $offset + 2, length: 2);
            $offset += 4 + (0 === ($flags & 0x0001) ? 2 : 4);
            $offset += match (true) {
                0 !== ($flags & 0x0008) => 2,
                0 !== ($flags & 0x0040) => 4,
                0 !== ($flags & 0x0080) => 8,
                default => 0,
            };
        } while (0 !== ($flags & 0x0020));

        return $data;
    }

    /**
     * The glyphs asked for, each once in its first place, followed by the components of composite
     * glyphs that were not asked for, in the order they are reached.
     *
     * @param list<int> $glyphIds
     *
     * @return list<int>
     *
     * @throws ParseException
     */
    private static function withComponents(OpenTypeFont $font, array $glyphIds): array
    {
        $order = [];
        $seen  = [];
        foreach ($glyphIds as $gid) {
            if (array_key_exists($gid, $seen)) {
                continue;
            }
            $seen[$gid] = true;
            $order[]    = $gid;
        }
        for ($i = 0; $i < count($order); ++$i) {
            foreach ($font->componentGlyphs($order[$i] ?? 0) as $component) {
                if (array_key_exists($component, $seen)) {
                    continue;
                }
                $seen[$component] = true;
                $order[]          = $component;
            }
        }

        return $order;
    }
}
