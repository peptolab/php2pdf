<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use Php2Pdf\Exception\ParseException;

use function array_key_exists;
use function array_keys;
use function array_pop;
use function count;
use function floor;
use function implode;
use function ksort;
use function log;
use function max;
use function min;
use function pack;
use function str_pad;
use function str_repeat;
use function str_split;
use function strlen;
use function substr;
use function substr_replace;
use function unpack;

use const SORT_STRING;

/**
 * Builds a TrueType font containing only the requested glyphs. Glyph ids are preserved, so text
 * already written with the original ids stays valid; unused glyphs become empty.
 *
 * @internal
 */
final class TrueTypeSubsetter
{
    /**
     * @param array<int, true> $glyphIds the glyphs to keep (components are added automatically)
     *
     * @throws ParseException
     */
    public static function subset(OpenTypeFont $font, array $glyphIds): string
    {
        $keep      = self::closeOverComponents($font, $glyphIds);
        $numGlyphs = min($font->numGlyphs, max(array_keys($keep)) + 1);

        [$glyf, $loca] = self::buildGlyf($font, $keep, $numGlyphs);
        $hmtx = self::buildHmtx($font, $numGlyphs);

        $head = $font->table('head') ?? '';
        $head = substr_replace($head, replace: "\0\0\0\0", offset: 8, length: 4); // checkSumAdjustment, recomputed below
        $head = substr_replace($head, replace: "\0\x01", offset: 50, length: 2); // indexToLocFormat: long offsets

        $tables = [
            'glyf' => $glyf,
            'head' => $head,
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

    /**
     * @param array<int, true> $keep
     *
     * @return array{0: string, 1: string} glyf data and long loca table
     *
     * @throws ParseException
     */
    private static function buildGlyf(OpenTypeFont $font, array $keep, int $numGlyphs): array
    {
        ksort($keep);
        $glyf   = '';
        $loca   = '';
        $offset = 0;
        $next   = 0; // the first glyph without a loca entry yet
        foreach ($keep as $gid => $_) {
            if ($gid < 0) {
                continue; // not a glyph id; the font has no such glyph
            }
            if ($gid >= $numGlyphs) {
                break;
            }
            // empty glyphs before this one all start (and end) where it starts
            $loca   .= str_repeat(pack('N', $offset), max(0, $gid - $next + 1));
            $next   = $gid + 1;
            $data   = $font->glyphData($gid);
            $pad    = (4 - (strlen($data) % 4)) % 4;
            $glyf   .= $data . str_repeat("\0", $pad);
            $offset += strlen($data) + $pad;
        }
        $loca .= str_repeat(pack('N', $offset), max(0, $numGlyphs - $next + 1));

        return [$glyf, $loca];
    }

    /**
     * Every glyph's advance and left side bearing as a long record. The font's own long records are
     * exactly those bytes, so they are copied; the glyphs after them repeat the last advance with
     * their short left side bearing (0 past the end of the table).
     *
     * @throws ParseException
     */
    private static function buildHmtx(OpenTypeFont $font, int $numGlyphs): string
    {
        $long = $font->longMetricCount();
        if (0 === $long) {
            $hmtx = '';
            for ($gid = 0; $gid < $numGlyphs; ++$gid) {
                $hmtx .= pack('nn', $font->advance($gid), $font->leftSideBearing($gid) & 0xFFFF);
            }

            return $hmtx;
        }

        $raw = $font->table('hmtx') ?? '';
        if ($numGlyphs <= $long) {
            return substr($raw, offset: 0, length: 4 * $numGlyphs);
        }
        $short   = $numGlyphs - $long;
        $bearing = substr($raw, offset: 4 * $long, length: 2 * $short);
        $bearing = str_pad(substr($bearing, offset: 0, length: strlen($bearing) & ~1), 2 * $short, pad_string: "\0");
        $advance = pack('n', $font->advance($long - 1));

        return (
            substr($raw, offset: 0, length: 4 * $long) . $advance . implode($advance, str_split($bearing, length: 2))
        );
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

    /**
     * @param array<int, true> $glyphIds
     *
     * @return array<int, true>
     *
     * @throws ParseException
     */
    private static function closeOverComponents(OpenTypeFont $font, array $glyphIds): array
    {
        $keep  = [0 => true];
        $queue = array_keys($glyphIds);
        while ([] !== $queue) {
            $gid = array_pop($queue);
            if (array_key_exists($gid, $keep) || $gid >= $font->numGlyphs) {
                continue;
            }
            $keep[$gid] = true;
            foreach ($font->componentGlyphs($gid) as $component) {
                if (array_key_exists($component, $keep)) {
                    continue;
                }
                $queue[] = $component;
            }
        }

        return $keep;
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
}
