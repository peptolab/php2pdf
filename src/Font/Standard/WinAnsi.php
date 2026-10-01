<?php

declare(strict_types=1);

namespace PhpPdf\Font\Standard;

/**
 * WinAnsiEncoding (Windows code page 1252 as PDF defines it): the single-byte encoding the
 * standard Latin fonts are written with.
 *
 * @internal
 */
final class WinAnsi
{
    /** @var array<int, int> code point => byte, for the bytes 0x80-0x9F that are not Latin-1 */
    private const array SPECIALS = [
        0x0152 => 0x8C,
        0x0153 => 0x9C,
        0x0160 => 0x8A,
        0x0161 => 0x9A,
        0x0178 => 0x9F,
        0x017D => 0x8E,
        0x017E => 0x9E,
        0x0192 => 0x83,
        0x02C6 => 0x88,
        0x02DC => 0x98,
        0x2013 => 0x96,
        0x2014 => 0x97,
        0x2018 => 0x91,
        0x2019 => 0x92,
        0x201A => 0x82,
        0x201C => 0x93,
        0x201D => 0x94,
        0x201E => 0x84,
        0x2020 => 0x86,
        0x2021 => 0x87,
        0x2022 => 0x95,
        0x2026 => 0x85,
        0x2030 => 0x89,
        0x2039 => 0x8B,
        0x203A => 0x9B,
        0x20AC => 0x80,
        0x2122 => 0x99,
    ];

    /** The byte for a code point, or null when WinAnsiEncoding cannot represent it. */
    public static function byte(int $codePoint): ?int
    {
        if (($codePoint >= 0x20 && $codePoint <= 0x7E) || ($codePoint >= 0xA0 && $codePoint <= 0xFF)) {
            return $codePoint;
        }

        return self::SPECIALS[$codePoint] ?? null;
    }

    /**
     * Every code point WinAnsiEncoding represents, with its byte.
     *
     * @return array<int, int> code point => byte
     */
    public static function map(): array
    {
        $map = [];
        for ($cp = 0x20; $cp <= 0xFF; ++$cp) {
            $byte = self::byte($cp);
            if (null === $byte) {
                continue;
            }
            $map[$cp] = $byte;
        }

        return $map + self::SPECIALS;
    }
}
