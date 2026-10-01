<?php

declare(strict_types=1);

namespace PhpPdf\Font\OpenType\Cff;

use PhpPdf\Exception\ParseException;
use PhpPdf\Exception\PdfException;
use PhpPdf\Font\OpenType\BinaryReader;

use function array_fill;
use function array_key_exists;
use function count;
use function sprintf;

/**
 * The parts of a CFF table a subsetter needs: the font name, the Top DICT, the global subrs,
 * the charstrings and, for every Font DICT, its Private DICT and local subrs. A name-keyed font
 * is read as a CID-keyed one with a single Font DICT, so both kinds are handled alike.
 *
 * @internal
 */
final readonly class CffTable
{
    public const int OP_CHARSET         = 15;
    public const int OP_CHARSTRINGS     = 17;
    public const int OP_PRIVATE         = 18;
    public const int OP_SUBRS           = 19;
    public const int OP_FONT_BBOX       = 5;
    public const int OP_CHARSTRING_TYPE = 1206;
    public const int OP_FONT_MATRIX     = 1207;
    public const int OP_ROS             = 1230;
    public const int OP_CID_COUNT       = 1234;
    public const int OP_FD_ARRAY        = 1236;
    public const int OP_FD_SELECT       = 1237;

    /**
     * @param array<int, DictEntry>                                  $top
     * @param list<string>                                           $globalSubrs
     * @param list<string>                                           $charStrings
     * @param list<array{dict: array<int, DictEntry>, private: array<int, DictEntry>, subrs: list<string>}> $fonts
     * @param list<int>                                              $fdSelect    glyph id => Font DICT
     */
    private function __construct(
        public string $name,
        public array $top,
        public array $globalSubrs,
        public array $charStrings,
        public array $fonts,
        public array $fdSelect,
        public bool $cidKeyed,
    ) {}

    /** @throws PdfException when the table is malformed or not a Type 2 charstring font */
    public static function parse(string $data): self
    {
        $reader = new BinaryReader($data);
        if (1 !== $reader->u8(0)) {
            throw new ParseException(sprintf('CFF version %d is not supported.', $reader->u8(0)));
        }
        [$names, $offset] = self::index($reader, $reader->u8(2));
        [$topDicts, $offset] = self::index($reader, $offset);
        [, $offset] = self::index($reader, $offset);
        [$globalSubrs] = self::index($reader, $offset);
        if (1 !== count($names) || 1 !== count($topDicts)) {
            throw new ParseException('CFF font sets with more than one font are not supported.');
        }
        $top = Dict::parse($topDicts[0]);
        if (2 !== (int) (($top[self::OP_CHARSTRING_TYPE] ?? null)?->values[0] ?? 2)) {
            throw new ParseException('Only Type 2 charstrings are supported.');
        }
        [$charStrings] = self::index($reader, self::offset($top, self::OP_CHARSTRINGS));
        $glyphs   = count($charStrings);
        $cidKeyed = array_key_exists(self::OP_ROS, $top);
        if (! $cidKeyed) {
            return new self(
                $names[0],
                $top,
                $globalSubrs,
                $charStrings,
                [self::font($reader, [], $top)],
                array_fill(0, $glyphs, value: 0),
                false,
            );
        }
        [$fdDicts] = self::index($reader, self::offset($top, self::OP_FD_ARRAY));
        $fonts = [];
        foreach ($fdDicts as $fdDict) {
            $dict    = Dict::parse($fdDict);
            $fonts[] = self::font($reader, $dict, $dict);
        }
        $fdSelect = self::fdSelect($reader, self::offset($top, self::OP_FD_SELECT), $glyphs);
        foreach ($fdSelect as $fd) {
            if ($fd >= count($fonts)) {
                throw new ParseException(sprintf('FDSelect refers to Font DICT %d of %d.', $fd, count($fonts)));
            }
        }

        return new self($names[0], $top, $globalSubrs, $charStrings, $fonts, $fdSelect, true);
    }

    /** @return list<int> glyph id => Font DICT */
    private static function fdSelect(BinaryReader $reader, int $offset, int $glyphs): array
    {
        $format = $reader->u8($offset);
        if (0 === $format) {
            $select = [];
            for ($gid = 0; $gid < $glyphs; ++$gid) {
                $select[] = $reader->u8($offset + 1 + $gid);
            }

            return $select;
        }
        if (3 !== $format) {
            throw new ParseException(sprintf('FDSelect format %d is not supported.', $format));
        }
        $ranges = $reader->u16($offset + 1);
        $select = [];
        for ($i = 0; $i < $ranges; ++$i) {
            $record = $offset + 3 + ($i * 3);
            $first  = $reader->u16($record);
            $fd     = $reader->u8($record + 2);
            $next   = $reader->u16($record + 3);
            for ($gid = $first; $gid < $next && $gid < $glyphs; ++$gid) {
                $select[$gid] = $fd;
            }
        }
        $result = [];
        for ($gid = 0; $gid < $glyphs; ++$gid) {
            $result[] = $select[$gid] ?? throw new ParseException(sprintf('FDSelect does not cover glyph %d.', $gid));
        }

        return $result;
    }

    /**
     * A Font DICT with its Private DICT and local subrs.
     *
     * @param array<int, DictEntry> $dict  the Font DICT (empty for a name-keyed font)
     * @param array<int, DictEntry> $owner the DICT holding the Private operator
     *
     * @return array{dict: array<int, DictEntry>, private: array<int, DictEntry>, subrs: list<string>}
     */
    private static function font(BinaryReader $reader, array $dict, array $owner): array
    {
        $entry = $owner[self::OP_PRIVATE] ?? throw new ParseException('CFF font has no Private DICT.');
        $size  = (int) ($entry->values[0] ?? 0);
        $start = (int) ($entry->values[1] ?? 0);
        if (0 === $size) {
            return ['dict' => $dict, 'private' => [], 'subrs' => []];
        }
        $private = Dict::parse($reader->bytes($start, $size));
        $subrs   = array_key_exists(self::OP_SUBRS, $private)
            ? self::index($reader, $start + self::offset($private, self::OP_SUBRS))[0]
            : [];

        return ['dict' => $dict, 'private' => $private, 'subrs' => $subrs];
    }

    /**
     * The items of the INDEX at $offset, and the offset just past it.
     *
     * @return array{0: list<string>, 1: int}
     */
    private static function index(BinaryReader $reader, int $offset): array
    {
        $count = $reader->u16($offset);
        if (0 === $count) {
            return [[], $offset + 2];
        }
        $size    = $reader->u8($offset + 2);
        $offsets = [];
        for ($i = 0; $i <= $count; ++$i) {
            $offsets[] = self::offsetValue($reader, $offset + 3 + ($i * $size), $size);
        }
        $base  = $offset + 2 + (($count + 1) * $size);
        $items = [];
        $end   = 1;
        for ($i = 0; $i < $count; ++$i) {
            $start = $offsets[$i] ?? 0;
            $end   = $offsets[$i + 1] ?? $start;
            if ($end < $start) {
                throw new ParseException('CFF INDEX offsets are not in order.');
            }
            $items[] = $reader->bytes($base + $start, $end - $start);
        }

        return [$items, $base + $end];
    }

    /** @param array<int, DictEntry> $dict */
    private static function offset(array $dict, int $operator): int
    {
        $entry = $dict[$operator] ?? throw new ParseException(sprintf('CFF DICT lacks operator %d.', $operator));

        return (int) ($entry->values[0] ?? 0);
    }

    private static function offsetValue(BinaryReader $reader, int $offset, int $size): int
    {
        if ($size < 1 || $size > 4) {
            throw new ParseException(sprintf('CFF offset size %d is invalid.', $size));
        }
        $value = 0;
        for ($i = 0; $i < $size; ++$i) {
            $value = ($value << 8) | $reader->u8($offset + $i);
        }

        return $value;
    }
}
