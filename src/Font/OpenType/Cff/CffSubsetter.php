<?php

declare(strict_types=1);

namespace PhpPdf\Font\OpenType\Cff;

use PhpPdf\Exception\PdfException;

use function array_filter;
use function array_key_exists;
use function array_keys;
use function chr;
use function count;
use function max;
use function pack;
use function strlen;

/**
 * Builds a bare CFF font (for a FontFile3 stream of subtype CIDFontType0C) containing only the
 * requested glyphs and the subroutines they call.
 *
 * The result is always CID-keyed with an identity charset, so CIDs equal the original glyph ids
 * and text already written with those ids stays valid. A name-keyed font becomes a CID-keyed
 * one with a single Font DICT. Unused glyphs become empty charstrings and unused subroutines
 * bare returns, which keeps every number (and so the subroutine bias) unchanged.
 *
 * @internal
 */
final class CffSubsetter
{
    private const string EMPTY_GLYPH = "\x0E";
    private const string EMPTY_SUBR  = "\x0B";

    /** [1 0 0 1 0 0] */
    private const string IDENTITY_MATRIX = "\x8C\x8B\x8B\x8C\x8B\x8B";

    /** [0.001 0 0 0.001 0 0] */
    private const string DEFAULT_MATRIX = "\x1E\x0A\x00\x1F\x8B\x8B\x1E\x0A\x00\x1F\x8B\x8B";

    /**
     * @param string           $cff      the font's CFF table
     * @param array<int, true> $glyphIds the glyphs to keep; .notdef is always kept
     *
     * @throws PdfException when the CFF data cannot be subset
     */
    public static function subset(string $cff, array $glyphIds): string
    {
        $table = CffTable::parse($cff);
        $total = count($table->charStrings);
        $keep  = [0 => true];
        foreach (array_keys($glyphIds) as $gid) {
            if ($gid < 0 || $gid >= $total) {
                continue;
            }
            $keep[$gid] = true;
        }
        $glyphs  = max(array_keys($keep)) + 1;
        $scanner = new CharStringScanner($table->globalSubrs);
        $chars   = [];
        for ($gid = 0; $gid < $glyphs; ++$gid) {
            if (! array_key_exists($gid, $keep)) {
                $chars[] = self::EMPTY_GLYPH;
                continue;
            }
            $fd = $table->fdSelect[$gid] ?? 0;
            $scanner->scan($table->charStrings[$gid] ?? '', $fd, $table->fonts[$fd]['subrs'] ?? []);
            $chars[] = $table->charStrings[$gid] ?? self::EMPTY_GLYPH;
        }

        return self::build(
            $table,
            $glyphs,
            $chars,
            self::prune($table->globalSubrs, $scanner->globalSubrs()),
            $scanner,
        );
    }

    /**
     * @param list<string> $chars
     * @param list<string> $globalSubrs
     */
    private static function build(
        CffTable $table,
        int $glyphs,
        array $chars,
        array $globalSubrs,
        CharStringScanner $scanner,
    ): string {
        $name        = Dict::index([$table->name]);
        $strings     = Dict::index(['Adobe', 'Identity']);
        $global      = Dict::index($globalSubrs);
        $charset     = $glyphs > 1 ? "\x02" . pack('nn', 1, $glyphs - 2) : "\x00";
        $fdSelect    = self::fdSelect($table, $glyphs);
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

    /** FDSelect format 3: runs of glyphs sharing a Font DICT. */
    private static function fdSelect(CffTable $table, int $glyphs): string
    {
        $ranges = '';
        $count  = 0;
        $last   = null;
        for ($gid = 0; $gid < $glyphs; ++$gid) {
            $fd = $table->fdSelect[$gid] ?? 0;
            if ($fd === $last) {
                continue;
            }
            $ranges .= pack('n', $gid) . chr($fd);
            $last   = $fd;
            ++$count;
        }

        return "\x03" . pack('n', $count) . $ranges . pack('n', $glyphs);
    }

    /**
     * The Font DICTs, each pointing at its Private DICT laid out from $offset on. A name-keyed
     * font's matrix moves to its Font DICT, and its Top DICT's matrix becomes the identity.
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
                CffTable::OP_FONT_MATRIX => $table->cidKeyed
                    ? self::operands($table->fonts[$fd]['dict'] ?? [], CffTable::OP_FONT_MATRIX)
                    : self::operands($table->top, CffTable::OP_FONT_MATRIX) ?? self::DEFAULT_MATRIX,
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
        $entries[CffTable::OP_FONT_MATRIX] = $table->cidKeyed
            ? self::operands($table->top, CffTable::OP_FONT_MATRIX)
            : self::IDENTITY_MATRIX;
        $entries[CffTable::OP_CID_COUNT]   = Dict::int($glyphs);
        $entries[CffTable::OP_CHARSET]     = Dict::int32($charset);
        $entries[CffTable::OP_FD_SELECT]   = Dict::int32($fdSelect);
        $entries[CffTable::OP_CHARSTRINGS] = Dict::int32($charStrings);
        $entries[CffTable::OP_FD_ARRAY]    = Dict::int32($fdArray);

        return Dict::encode(array_filter($entries, static fn(?string $v): bool => null !== $v));
    }
}
