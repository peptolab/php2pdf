<?php

declare(strict_types=1);

namespace PhpPdf\Font\OpenType;

use function array_key_exists;
use function array_keys;

/**
 * Pair adjustment (type 2) lookups of the GPOS "kern" feature, resolved lazily per glyph pair.
 * Only horizontal advance adjustments of the first glyph are used.
 *
 * @internal
 */
final class GposKerning
{
    /** @var list<array{coverage: array<int, int>, format: int, offset: int, vf1: int, vf2: int}> */
    private array $subtables = [];

    /** @var array<int, int|null> pair key => adjustment, memoised */
    private array $cache = [];

    /** @var array<int, array<int, int>> class definition offset => glyph => class */
    private array $classDefs = [];

    public function __construct(
        private readonly BinaryReader $gpos,
    ) {
        foreach ($this->kernLookups() as $lookup) {
            $this->collectPairPos($lookup);
        }
    }

    private static function popCount(int $v): int
    {
        $count = 0;
        while (0 !== $v) {
            $count += $v & 1;
            $v     >>= 1;
        }

        return $count;
    }

    public function adjustment(int $left, int $right): int
    {
        $key = ($left << 16) | $right;
        if (array_key_exists($key, $this->cache)) {
            return $this->cache[$key] ?? 0;
        }
        $value = null;
        foreach ($this->subtables as $subtable) {
            $index = $subtable['coverage'][$left] ?? null;
            if (null === $index) {
                continue;
            }
            $value = 1 === $subtable['format']
                ? $this->format1($subtable['offset'], $subtable['vf1'], $subtable['vf2'], $index, $right)
                : $this->format2($subtable['offset'], $subtable['vf1'], $subtable['vf2'], $left, $right);
            if (null !== $value) {
                break;
            }
        }
        $this->cache[$key] = $value;

        return $value ?? 0;
    }

    public function hasPairs(): bool
    {
        return [] !== $this->subtables;
    }

    private function addPairPos(int $offset): void
    {
        $r      = $this->gpos;
        $format = $r->u16($offset);
        if (1 !== $format && 2 !== $format) {
            return;
        }
        $vf1 = $r->u16($offset + 4);
        $vf2 = $r->u16($offset + 6);
        if (0 === ($vf1 & 0x0004)) {
            return;
        }
        $this->subtables[] = [
            'coverage' => $this->coverage($offset + $r->u16($offset + 2)),
            'format'   => $format,
            'offset'   => $offset,
            'vf1'      => $vf1,
            'vf2'      => $vf2,
        ];
    }

    /** @return array<int, int> glyph => class */
    private function classDef(int $offset): array
    {
        $cached = $this->classDefs[$offset] ?? null;
        if (null !== $cached) {
            return $cached;
        }
        $format = $this->gpos->u16($offset);
        $map    = match ($format) {
            1       => $this->classDefFormat1($offset),
            2       => $this->classDefFormat2($offset),
            default => [],
        };

        return $this->classDefs[$offset] = $map;
    }

    /** @return array<int, int> */
    private function classDefFormat1(int $offset): array
    {
        $r     = $this->gpos;
        $start = $r->u16($offset + 2);
        $map   = [];
        foreach ($r->u16List($offset + 6, $r->u16($offset + 4)) as $i => $class) {
            if (0 === $class) {
                continue;
            }
            $map[$start + $i] = $class;
        }

        return $map;
    }

    /** @return array<int, int> */
    private function classDefFormat2(int $offset): array
    {
        $r     = $this->gpos;
        $count = $r->u16($offset + 2);
        $map   = [];
        for ($i = 0; $i < $count; ++$i) {
            $record = $offset + 4 + (6 * $i);
            $class  = $r->u16($record + 4);
            if (0 === $class) {
                continue;
            }
            $end = $r->u16($record + 2);
            for ($glyph = $r->u16($record); $glyph <= $end; ++$glyph) {
                $map[$glyph] = $class;
            }
        }

        return $map;
    }

    private function collectPairPos(int $lookup): void
    {
        $r     = $this->gpos;
        $type  = $r->u16($lookup);
        $count = $r->u16($lookup + 4);
        foreach ($r->u16List($lookup + 6, $count) as $offset) {
            $subtable = $lookup + $offset;
            $realType = $type;
            if (9 === $type) {
                $realType = $r->u16($subtable + 2);
                $subtable += $r->u32($subtable + 4);
            }
            if (2 === $realType) {
                $this->addPairPos($subtable);
            }
        }
    }

    /** @return array<int, int> glyph => coverage index */
    private function coverage(int $offset): array
    {
        $r      = $this->gpos;
        $format = $r->u16($offset);
        $count  = $r->u16($offset + 2);
        $map    = [];
        if (1 === $format) {
            foreach ($r->u16List($offset + 4, $count) as $index => $glyph) {
                $map[$glyph] = $index;
            }

            return $map;
        }
        for ($i = 0; $i < $count; ++$i) {
            $record = $offset + 4 + (6 * $i);
            $start  = $r->u16($record);
            $end    = $r->u16($record + 2);
            $base   = $r->u16($record + 4);
            for ($glyph = $start; $glyph <= $end; ++$glyph) {
                $map[$glyph] = $base + ($glyph - $start);
            }
        }

        return $map;
    }

    private function format1(int $offset, int $vf1, int $vf2, int $coverageIndex, int $right): ?int
    {
        $r          = $this->gpos;
        $pairSet    = $offset + $r->u16($offset + 10 + (2 * $coverageIndex));
        $recordSize = 2 + (2 * self::popCount($vf1)) + (2 * self::popCount($vf2));
        $advance    = 2 * self::popCount($vf1 & 0x0003);

        // Pair records are sorted by second glyph; a binary search keeps lookups cheap.
        $lo = 0;
        $hi = $r->u16($pairSet) - 1;
        while ($lo <= $hi) {
            $mid    = ($lo + $hi) >> 1;
            $record = $pairSet + 2 + ($mid * $recordSize);
            $second = $r->u16($record);
            if ($second === $right) {
                return $r->i16($record + 2 + $advance);
            }
            if ($second < $right) {
                $lo = $mid + 1;
                continue;
            }
            $hi = $mid - 1;
        }

        return null;
    }

    private function format2(int $offset, int $vf1, int $vf2, int $left, int $right): ?int
    {
        $r           = $this->gpos;
        $class1      = $this->classDef($offset + $r->u16($offset + 8))[$left] ?? 0;
        $class2      = $this->classDef($offset + $r->u16($offset + 10))[$right] ?? 0;
        $class1Count = $r->u16($offset + 12);
        $class2Count = $r->u16($offset + 14);
        if ($class1 >= $class1Count || $class2 >= $class2Count) {
            return null;
        }
        $recordSize = (2 * self::popCount($vf1)) + (2 * self::popCount($vf2));
        $advance    = 2 * self::popCount($vf1 & 0x0003);
        $record     = $offset + 16 + ((($class1 * $class2Count) + $class2) * $recordSize);
        $value      = $r->i16($record + $advance);

        return 0 === $value ? null : $value;
    }

    /**
     * Offsets of the lookup tables the "kern" feature references.
     *
     * @return list<int>
     */
    private function kernLookups(): array
    {
        $r            = $this->gpos;
        $featureList  = $r->u16(6);
        $lookupList   = $r->u16(8);
        $featureCount = $r->u16($featureList);
        $indices      = [];
        for ($i = 0; $i < $featureCount; ++$i) {
            $record = $featureList + 2 + (6 * $i);
            if ('kern' !== $r->tag($record)) {
                continue;
            }
            $feature = $featureList + $r->u16($record + 4);
            foreach ($r->u16List($feature + 4, $r->u16($feature + 2)) as $index) {
                $indices[$index] = true;
            }
        }

        $lookupCount = $r->u16($lookupList);
        $lookups     = [];
        foreach (array_keys($indices) as $index) {
            if ($index >= $lookupCount) {
                continue;
            }
            $lookups[] = $lookupList + $r->u16($lookupList + 2 + (2 * $index));
        }

        return $lookups;
    }
}
