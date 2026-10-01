<?php

declare(strict_types=1);

namespace PhpPdf\Font\OpenType;

use function array_key_exists;
use function is_string;
use function mb_convert_encoding;
use function preg_match;

/**
 * Reads the name records that matter: family, subfamily, full and PostScript names.
 *
 * @internal
 */
final class NameTable
{
    public const int FAMILY                = 1;
    public const int SUBFAMILY             = 2;
    public const int FULL_NAME             = 4;
    public const int POSTSCRIPT            = 6;
    public const int TYPOGRAPHIC_FAMILY    = 16;
    public const int TYPOGRAPHIC_SUBFAMILY = 17;

    private const array WANTED = [
        self::FAMILY                => true,
        self::SUBFAMILY             => true,
        self::FULL_NAME             => true,
        self::POSTSCRIPT            => true,
        self::TYPOGRAPHIC_FAMILY    => true,
        self::TYPOGRAPHIC_SUBFAMILY => true,
    ];

    /** @return array<int, string> name id => UTF-8 string */
    public static function parse(BinaryReader $name): array
    {
        $count      = $name->u16(2);
        $stringBase = $name->u16(4);
        $names      = [];
        $ranks      = [];
        for ($i = 0; $i < $count; ++$i) {
            $record   = 6 + (12 * $i);
            $platform = $name->u16($record);
            $encoding = $name->u16($record + 2);
            $language = $name->u16($record + 4);
            $nameId   = $name->u16($record + 6);
            $length   = $name->u16($record + 8);
            $offset   = $name->u16($record + 10);
            if (! array_key_exists($nameId, self::WANTED) || ($stringBase + $offset + $length) > $name->length) {
                continue;
            }
            $rank = match (true) {
                3 === $platform && 1 === $encoding && 0x409 === $language => 4,
                3 === $platform && 1 === $encoding => 3,
                0 === $platform => 2,
                1 === $platform && 0 === $encoding => 1,
                default => 0,
            };
            if (0 === $rank || $rank <= ($ranks[$nameId] ?? 0)) {
                continue;
            }
            $raw            = $name->bytes($stringBase + $offset, $length);
            $names[$nameId] = 1 === $platform ? self::macRoman($raw) : self::utf16($raw);
            $ranks[$nameId] = $rank;
        }

        return $names;
    }

    private static function macRoman(string $raw): string
    {
        // Mac Roman names are almost always ASCII; mbstring has no Mac Roman codec, and Latin-1 is
        // the closest available fallback for the few accented names seen in the wild.
        if (preg_match('/[\x80-\xFF]/', $raw) !== 1) {
            return $raw;
        }
        $converted = mb_convert_encoding($raw, to_encoding: 'UTF-8', from_encoding: 'ISO-8859-1');

        return is_string($converted) ? $converted : $raw;
    }

    private static function utf16(string $raw): string
    {
        $converted = mb_convert_encoding($raw, to_encoding: 'UTF-8', from_encoding: 'UTF-16BE');

        return is_string($converted) ? $converted : '';
    }
}
