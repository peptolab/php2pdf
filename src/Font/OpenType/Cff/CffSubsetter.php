<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType\Cff;

use Php2Pdf\Exception\PdfException;

use function array_filter;
use function array_key_exists;
use function array_map;
use function chr;
use function count;
use function floatval;
use function pack;
use function strlen;

/**
 * Builds a bare CFF font (for a FontFile3 stream of subtype CIDFontType0C) containing only the
 * requested glyphs and the subroutines they call.
 *
 * The glyphs are numbered in the order given, and the result is CID-keyed with an identity
 * charset, so a glyph's CID is its place in that order. A name-keyed font becomes a CID-keyed one
 * with a single Font DICT. Unused subroutines become bare returns, which keeps every subroutine
 * number (and so the bias) unchanged.
 *
 * @internal
 */
final class CffSubsetter
{
    private const string EMPTY_GLYPH = "\x0E";
    private const string EMPTY_SUBR  = "\x0B";

    /**
     * @param string    $cff      the font's CFF table
     * @param list<int> $glyphIds the glyphs to keep, in their new order; the first should be
     *                            .notdef (0)
     *
     * @throws PdfException when the CFF data cannot be subset
     */
    public static function subset(string $cff, array $glyphIds): string
    {
        return self::subsetTable(CffTable::parse($cff), $glyphIds);
    }

    /**
     * Subsets an already parsed table, such as a CFF2 font's default instance.
     *
     * @param list<int> $glyphIds the glyphs to keep, in their new order; the first should be
     *                            .notdef (0). An id the font lacks keeps its place as an empty
     *                            glyph.
     *
     * @throws PdfException when the CFF data cannot be subset
     */
    public static function subsetTable(CffTable $table, array $glyphIds): string
    {
        $scanner  = new CharStringScanner($table->globalSubrs);
        $chars    = [];
        $fdSelect = [];
        foreach ($glyphIds as $gid) {
            $fd         = $table->fdSelect[$gid] ?? 0;
            $fdSelect[] = $fd;
            $charString = $table->charStrings[$gid] ?? null;
            if (null === $charString) {
                $chars[] = self::EMPTY_GLYPH;
                continue;
            }
            $scanner->scan($charString, $fd, $table->fonts[$fd]['subrs'] ?? []);
            $chars[] = $charString;
        }

        return self::build(
            $table,
            $chars,
            $fdSelect,
            self::prune($table->globalSubrs, $scanner->globalSubrs()),
            $scanner,
        );
    }

    /**
     * @param list<string> $chars       each new glyph's charstring
     * @param list<int>    $fdSelects   each new glyph's Font DICT
     * @param list<string> $globalSubrs
     */
    private static function build(
        CffTable $table,
        array $chars,
        array $fdSelects,
        array $globalSubrs,
        CharStringScanner $scanner,
    ): string {
        $glyphs      = count($chars);
        $name        = Dict::index([$table->name]);
        $strings     = Dict::index(['Adobe', 'Identity']);
        $global      = Dict::index($globalSubrs);
        $charset     = $glyphs > 1 ? "\x02" . pack('nn', 1, $glyphs - 2) : "\x00";
        $fdSelect    = self::fdSelect($fdSelects);
        $charStrings = Dict::index($chars);

        /** @var list<array{0: string, 1: string}> $privates Private DICT without Subrs, and the subrs INDEX */
        $privates = [];
        foreach ($table->fonts as $fd => $font) {
            $entries = [];
            foreach ($font['private'] as $operator => $entry) {
                if (CffTable::OP_SUBRS === $operator) {
                    continue;
                }
                $entries[$operator] = $entry->operands;
            }
            $subrs = [] === $font['subrs']
                ? ''
                : Dict::index(self::prune($font['subrs'], $scanner->localSubrs($fd)));
            $privates[] = [Dict::encode($entries), $subrs];
        }

        $sizing            = self::topDict($table, $glyphs, 0, 0, 0, 0);
        $offset            = 4 + strlen($name) + strlen(Dict::index([$sizing])) + strlen($strings) + strlen($global);
        $charsetOffset     = $offset;
        $fdSelectOffset    = $charsetOffset + strlen($charset);
        $charStringsOffset = $fdSelectOffset + strlen($fdSelect);
        $fdArrayOffset     = $charStringsOffset + strlen($charStrings);
        $offset            = $fdArrayOffset + strlen(Dict::index(self::fontDicts($table, $privates, 0)));

        $privateData = '';
        foreach ($privates as [$dict, $subrs]) {
            $privateData .= self::privateDict($dict, $subrs) . $subrs;
        }

        return (
            "\x01\x00\x04\x04"
                . $name
                . Dict::index([self::topDict(
                    $table,
                    $glyphs,
                    $charsetOffset,
                    $fdSelectOffset,
                    $charStringsOffset,
                    $fdArrayOffset,
                )])
                . $strings
                . $global
                . $charset
                . $fdSelect
                . $charStrings
                . Dict::index(self::fontDicts($table, $privates, $offset))
                . $privateData
        );
    }

    /**
     * FDSelect format 3: runs of glyphs sharing a Font DICT.
     *
     * @param list<int> $fds each glyph's Font DICT
     */
    private static function fdSelect(array $fds): string
    {
        $ranges = '';
        $count  = 0;
        $last   = null;
        foreach ($fds as $gid => $fd) {
            if ($fd === $last) {
                continue;
            }
            $ranges .= pack('n', $gid) . chr($fd);
            $last   = $fd;
            ++$count;
        }

        return "\x03" . pack('n', $count) . $ranges . pack('n', count($fds));
    }

    /**
     * The Font DICTs, each pointing at its Private DICT laid out from $offset on, with their
     * matrices unless hoistedMatrix() moves them to the Top DICT.
     *
     * @param list<array{0: string, 1: string}> $privates
     *
     * @return list<string>
     */
    private static function fontDicts(CffTable $table, array $privates, int $offset): array
    {
        $dicts = [];
        foreach ($privates as $fd => [$dict, $subrs]) {
            $size    = strlen(self::privateDict($dict, $subrs));
            $entries = [
                CffTable::OP_FONT_MATRIX => null === self::hoistedMatrix($table)
                    ? self::operands($table->fonts[$fd]['dict'] ?? [], CffTable::OP_FONT_MATRIX)
                    : null,
                CffTable::OP_PRIVATE     => Dict::int32($size) . Dict::int32($offset),
            ];
            $dicts[] = Dict::encode(array_filter(
                $entries,
                static fn(?string $v): bool => null !== $v,
            ));
            $offset += $size + strlen($subrs);
        }

        return $dicts;
    }

    /**
     * The Font DICTs' shared matrix, when the Top DICT has none or the identity, to be written in
     * the Top DICT instead. Readers are meant to combine the two, but macOS's renderer uses only
     * the Top DICT's matrix (drawing glyphs a thousand times too large, off the page) and poppler
     * sizes its glyph cache from it. Null when the Font DICTs differ or carry no matrix.
     */
    private static function hoistedMatrix(CffTable $table): ?string
    {
        $top = $table->top[CffTable::OP_FONT_MATRIX] ?? null;
        if (null !== $top && [1.0, 0.0, 0.0, 1.0, 0.0, 0.0] !== array_map(floatval(...), $top->values)) {
            return null;
        }
        $matrices = [];
        foreach ($table->fonts as $font) {
            $matrices[] = self::operands($font['dict'], CffTable::OP_FONT_MATRIX);
        }
        $shared = $matrices[0] ?? null;
        foreach ($matrices as $matrix) {
            if ($matrix !== $shared) {
                return null;
            }
        }

        return $shared;
    }

    /**
     * The operands of a DICT entry, as written, or null when the DICT lacks it.
     *
     * @param array<int, DictEntry> $dict
     */
    private static function operands(array $dict, int $operator): ?string
    {
        $entry = $dict[$operator] ?? null;

        return null === $entry ? null : $entry->operands;
    }

    /** A Private DICT with its Subrs operator, when it has subrs, pointing just past itself. */
    private static function privateDict(string $dict, string $subrs): string
    {
        if ('' === $subrs) {
            return $dict;
        }

        return $dict . Dict::encode([CffTable::OP_SUBRS => Dict::int32(strlen($dict) + 6)]);
    }

    /**
     * The subroutines, with those not called replaced by a bare return.
     *
     * @param list<string>     $subrs
     * @param array<int, true> $used
     *
     * @return list<string>
     */
    private static function prune(array $subrs, array $used): array
    {
        $pruned = [];
        foreach ($subrs as $i => $subr) {
            $pruned[] = array_key_exists($i, $used) ? $subr : self::EMPTY_SUBR;
        }

        return $pruned;
    }

    private static function topDict(
        CffTable $table,
        int $glyphs,
        int $charset,
        int $fdSelect,
        int $charStrings,
        int $fdArray,
    ): string {
        $entries                           = [CffTable::OP_ROS => Dict::int(391) . Dict::int(392) . Dict::int(0)];
        $entries[CffTable::OP_FONT_BBOX]   = self::operands($table->top, CffTable::OP_FONT_BBOX);
        $entries[CffTable::OP_FONT_MATRIX] = self::hoistedMatrix($table)
            ?? self::operands($table->top, CffTable::OP_FONT_MATRIX);
        $entries[CffTable::OP_CID_COUNT]   = Dict::int($glyphs);
        $entries[CffTable::OP_CHARSET]     = Dict::int32($charset);
        $entries[CffTable::OP_FD_SELECT]   = Dict::int32($fdSelect);
        $entries[CffTable::OP_CHARSTRINGS] = Dict::int32($charStrings);
        $entries[CffTable::OP_FD_ARRAY]    = Dict::int32($fdArray);

        return Dict::encode(array_filter($entries, static fn(?string $v): bool => null !== $v));
    }
}
