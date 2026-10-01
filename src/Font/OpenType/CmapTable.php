<?php

declare(strict_types=1);

namespace PhpPdf\Font\OpenType;

/**
 * Parses the Unicode subtables of cmap (formats 4 and 12) into a code point to glyph map.
 *
 * @internal
 */
final class CmapTable
{
    /** @return array<int, int> code point => glyph id */
    public static function parse(BinaryReader $cmap): array
    {
        $count = $cmap->u16(2);
        $best  = null;
        $score = 0;
        for ($i = 0; $i < $count; ++$i) {
            $record   = 4 + (8 * $i);
            $platform = $cmap->u16($record);
            $encoding = $cmap->u16($record + 2);
            $rank     = match (true) {
                3 === $platform && 10 === $encoding => 4,
                0 === $platform && 4 === $encoding => 3,
                3 === $platform && 1 === $encoding => 2,
                0 === $platform => 1,
                default => 0,
            };
            if ($rank > $score) {
                $score = $rank;
                $best  = $cmap->u32($record + 4);
            }
        }
        if (null === $best) {
            return [];
        }

        return match ($cmap->u16($best)) {
            4       => self::format4($cmap, $best),
            12      => self::format12($cmap, $best),
            default => [],
        };
    }

    /** @return array<int, int> */
    private static function format12(BinaryReader $r, int $base): array
    {
        $groups = $r->u32($base + 12);
        $map    = [];
        for ($i = 0; $i < $groups; ++$i) {
            $record = $base + 16 + (12 * $i);
            $start  = $r->u32($record);
            $end    = $r->u32($record + 4);
            $glyph  = $r->u32($record + 8);
            if (($end - $start) > 0x10_FFFF) {
                continue;
            }
            for ($code = $start; $code <= $end; ++$code) {
                $map[$code] = $glyph + ($code - $start);
            }
        }

        return $map;
    }

    /** @return array<int, int> */
    private static function format4(BinaryReader $r, int $base): array
    {
        $segCount   = $r->u16($base + 6) >> 1;
        $endCodes   = $r->u16List($base + 14, $segCount);
        $startBase  = $base + 16 + (2 * $segCount);
        $startCodes = $r->u16List($startBase, $segCount);
        $deltaBase  = $startBase + (2 * $segCount);
        $deltas     = $r->u16List($deltaBase, $segCount);
        $rangeBase  = $deltaBase + (2 * $segCount);
        $ranges     = $r->u16List($rangeBase, $segCount);

        $map = [];
        foreach ($endCodes as $i => $end) {
            $start = $startCodes[$i] ?? 0xFFFF;
            if (0xFFFF === $start) {
                continue;
            }
            $delta = $deltas[$i] ?? 0;
            $range = $ranges[$i] ?? 0;
            for ($code = $start; $code <= $end; ++$code) {
                $glyph = self::format4Glyph($r, $rangeBase + (2 * $i), $code - $start, $code, $delta, $range);
                if (0 !== $glyph) {
                    $map[$code] = $glyph;
                }
            }
        }

        return $map;
    }

    private static function format4Glyph(
        BinaryReader $r,
        int $rangeAddress,
        int $index,
        int $code,
        int $delta,
        int $range,
    ): int {
        if (0 === $range) {
            return ($code + $delta) & 0xFFFF;
        }
        $address = $rangeAddress + $range + (2 * $index);
        if (($address + 2) > $r->length) {
            return 0;
        }
        $glyph = $r->u16($address);

        return 0 === $glyph ? 0 : ($glyph + $delta) & 0xFFFF;
    }
}
