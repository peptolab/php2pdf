<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType\Cff;

use Php2Pdf\Exception\ParseException;
use Php2Pdf\Font\OpenType\BinaryReader;

use function array_fill;
use function array_key_exists;
use function array_map;
use function array_pop;
use function array_slice;
use function array_splice;
use function count;
use function implode;
use function sprintf;

/**
 * Reads a CFF2 table (the outlines of a variable OpenType font) as its default instance, in the
 * parts a CFF subsetter uses: Top DICT, global subrs, Type 2 charstrings, and per Font DICT its
 * Private DICT and local subrs, with every blend resolved to its default value.
 *
 * @internal
 */
final class Cff2Reader
{
    private const int OP_PRIVATE  = 18;
    private const int OP_SUBRS    = 19;
    private const int OP_VSINDEX  = 22;
    private const int OP_BLEND    = 23;
    private const int OP_VSTORE   = 24;
    private const int OP_CHARSTRS = 17;
    private const int OP_FD_ARRAY = 1236;
    private const int OP_FD_SEL   = 1237;

    private readonly BinaryReader $reader;

    /** @var list<int> regions per item variation data */
    private array $regionCounts = [];

    /** @throws ParseException */
    public function __construct(string $data)
    {
        $this->reader = new BinaryReader($data);
        if (2 !== $this->reader->u8(0)) {
            throw new ParseException(sprintf('CFF2 version %d is not supported.', $this->reader->u8(0)));
        }
    }

    /**
     * @param array<int, DictEntry> $dict
     *
     * @throws ParseException
     */
    private static function offset(array $dict, int $operator): int
    {
        $entry = $dict[$operator] ?? throw new ParseException(sprintf('CFF2 DICT lacks operator %d.', $operator));

        return (int) ($entry->values[0] ?? 0);
    }

    /**
     * The table's parts: Top DICT, global subrs, charstrings, Font DICTs with their Private DICTs
     * and subrs, and the Font DICT of each glyph.
     *
     * @return array{0: array<int, DictEntry>, 1: list<string>, 2: list<string>, 3: list<array{dict: array<int, DictEntry>, private: array<int, DictEntry>, subrs: list<string>}>, 4: list<int>}
     *
     * @throws ParseException
     */
    public function read(): array
    {
        $r          = $this->reader;
        $headerSize = $r->u8(2);
        $topLength  = $r->u16(3);
        $top        = $this->dict($r->bytes($headerSize, $topLength), 0);
        [$globalSubrs] = $this->index($headerSize + $topLength);
        if (array_key_exists(self::OP_VSTORE, $top)) {
            $this->readRegionCounts((int) ($top[self::OP_VSTORE]->values[0] ?? 0));
        }
        [$charStrings] = $this->index(self::offset($top, self::OP_CHARSTRS));
        [$fontDicts] = $this->index(self::offset($top, self::OP_FD_ARRAY));
        $fdSelect = array_key_exists(self::OP_FD_SEL, $top)
            ? $this->fdSelect(self::offset($top, self::OP_FD_SEL), count($charStrings))
            : array_fill(0, count($charStrings), value: 0);

        $fonts     = [];
        $locals    = [];
        $vsindexes = [];
        foreach ($fontDicts as $fontDict) {
            $dict    = $this->dict($fontDict, 0);
            $private = $dict[self::OP_PRIVATE] ?? throw new ParseException(
                    'CFF2 Font DICT has no Private DICT.',
                );
            $size    = (int) ($private->values[0] ?? 0);
            $start   = (int) ($private->values[1] ?? 0);
            $raw     = $this->rawVsindex($r->bytes($start, $size));
            $entries = $this->dict($r->bytes($start, $size), $this->regionCounts[$raw] ?? 0);
            $subrs   = array_key_exists(self::OP_SUBRS, $entries)
                ? $this->index($start + self::offset($entries, self::OP_SUBRS))[0]
                : [];
            $fonts[]     = ['dict' => $dict, 'private' => $entries];
            $locals[]    = $subrs;
            $vsindexes[] = $raw;
        }

        $converter = new Cff2CharStrings($globalSubrs, $locals, $this->regionCounts, $vsindexes);
        $glyphs    = [];
        foreach ($charStrings as $gid => $charString) {
            $glyphs[] = $converter->glyph($charString, $fdSelect[$gid] ?? 0);
        }
        $out = [];
        foreach ($fonts as $fd => $font) {
            unset($font['private'][self::OP_VSINDEX]);
            $out[] = ['dict' => $font['dict'], 'private' => $font['private'], 'subrs' => $converter->localSubrs($fd)];
        }

        return [$top, $converter->globalSubrs(), $glyphs, $out, $fdSelect];
    }

    /**
     * Parses a CFF2 DICT, resolving blends to their default values: of the last n * (regions + 1)
     * operands (n being the top of the stack), the first n are the defaults. Every entry's
     * operands are encoded again from its values.
     *
     * @return array<int, DictEntry>
     *
     * @throws ParseException
     */
    private function dict(string $data, int $regions): array
    {
        $entries = [];
        /** @var list<int|float> $stack */
        $stack = [];
        foreach (Dict::tokens($data) as [$isOperator, $value]) {
            if (! $isOperator) {
                $stack[] = $value;
                continue;
            }
            if (self::OP_BLEND === $value) {
                $n     = (int) array_pop($stack);
                $start = count($stack) - ($n * ($regions + 1));
                if ($n < 0 || $start < 0) {
                    throw new ParseException('CFF2 DICT blend has too few operands.');
                }
                array_splice($stack, $start + $n, $n * $regions);
                continue;
            }
            $entries[(int) $value] = new DictEntry(implode('', array_map(Dict::number(...), $stack)), $stack);
            $stack                 = [];
        }

        return $entries;
    }

    /**
     * The FDSelect table: formats 0, 3 and 4.
     *
     * @return list<int>
     *
     * @throws ParseException
     */
    private function fdSelect(int $offset, int $glyphs): array
    {
        $r      = $this->reader;
        $format = $r->u8($offset);
        $select = [];
        if (0 === $format) {
            for ($gid = 0; $gid < $glyphs; ++$gid) {
                $select[] = $r->u8($offset + 1 + $gid);
            }

            return $select;
        }
        $wide   = 4 === $format;
        $ranges = $wide ? $r->u32($offset + 1) : $r->u16($offset + 1);
        $record = $wide ? 6 : 3;
        $base   = $offset + ($wide ? 5 : 3);
        $map    = [];
        for ($k = 0; $k < $ranges; ++$k) {
            $at    = $base + ($k * $record);
            $first = $wide ? $r->u32($at) : $r->u16($at);
            $fd    = $wide ? $r->u16($at + 4) : $r->u8($at + 2);
            $next  = $wide ? $r->u32($at + $record) : $r->u16($at + $record);
            for ($gid = $first; $gid < $next && $gid < $glyphs; ++$gid) {
                $map[$gid] = $fd;
            }
        }
        if (3 !== $format && ! $wide) {
            throw new ParseException(sprintf('FDSelect format %d is not supported.', $format));
        }
        for ($gid = 0; $gid < $glyphs; ++$gid) {
            $select[] = $map[$gid] ?? 0;
        }

        return $select;
    }

    /**
     * A CFF2 INDEX: a 32-bit count, then offsets and data.
     *
     * @return array{0: list<string>, 1: int}
     *
     * @throws ParseException
     */
    private function index(int $offset): array
    {
        $r     = $this->reader;
        $count = $r->u32($offset);
        if (0 === $count) {
            return [[], $offset + 4];
        }
        $size    = $r->u8($offset + 4);
        $offsets = [];
        for ($k = 0; $k <= $count; ++$k) {
            $value = 0;
            for ($b = 0; $b < $size; ++$b) {
                $value = ($value << 8) | $r->u8($offset + 5 + ($k * $size) + $b);
            }
            $offsets[] = $value;
        }
        $base  = $offset + 5 + (($count + 1) * $size) - 1;
        $items = [];
        foreach (array_slice($offsets, offset: 0, length: $count) as $k => $from) {
            $items[] = $r->bytes($base + $from, ($offsets[$k + 1] ?? $from) - $from);
        }

        $last = $offsets[count($offsets) - 1] ?? 1;

        return [$items, $base + $last];
    }

    /** The vsindex a Private DICT sets, 0 by default. */
    private function rawVsindex(string $data): int
    {
        $last = 0;
        foreach (Dict::tokens($data) as [$isOperator, $value]) {
            if ($isOperator && self::OP_VSINDEX === $value) {
                return (int) $last;
            }
            $last = $isOperator ? 0 : $value;
        }

        return 0;
    }

    /**
     * Reads how many regions each item variation data in the variation store blends over.
     *
     * @throws ParseException
     */
    private function readRegionCounts(int $offset): void
    {
        $r     = $this->reader;
        $store = $offset + 2;
        $count = $r->u16($store + 6);
        for ($k = 0; $k < $count; ++$k) {
            $data                 = $store + $r->u32($store + 8 + (4 * $k));
            $this->regionCounts[] = $r->u16($data + 4);
        }
    }
}
