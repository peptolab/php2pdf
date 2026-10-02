<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use function mb_ord;
use function preg_match;

/**
 * The scripts whose text needs shaping beyond what the font engine does: right-to-left scripts
 * (bidi reordering, and joining for Arabic and its relatives), Indic and other South and South
 * East Asian scripts (reordering, conjuncts and mark placement), and conjoining Hangul jamo.
 *
 * @internal
 */
final class ComplexScripts
{
    /** @var list<array{0: int, 1: int, 2: string}> first and last code point, and the script */
    private const array RANGES = [
        [0x0590,   0x05FF,   'Hebrew'],
        [0x0600,   0x06FF,   'Arabic'],
        [0x0700,   0x074F,   'Syriac'],
        [0x0750,   0x077F,   'Arabic'],
        [0x0780,   0x07BF,   'Thaana'],
        [0x07C0,   0x07FF,   'NKo'],
        [0x0800,   0x083F,   'Samaritan'],
        [0x0840,   0x085F,   'Mandaic'],
        [0x0860,   0x086F,   'Syriac'],
        [0x0870,   0x08FF,   'Arabic'],
        [0x0900,   0x097F,   'Devanagari'],
        [0x0980,   0x09FF,   'Bengali'],
        [0x0A00,   0x0A7F,   'Gurmukhi'],
        [0x0A80,   0x0AFF,   'Gujarati'],
        [0x0B00,   0x0B7F,   'Oriya'],
        [0x0B80,   0x0BFF,   'Tamil'],
        [0x0C00,   0x0C7F,   'Telugu'],
        [0x0C80,   0x0CFF,   'Kannada'],
        [0x0D00,   0x0D7F,   'Malayalam'],
        [0x0D80,   0x0DFF,   'Sinhala'],
        [0x0E00,   0x0E7F,   'Thai'],
        [0x0E80,   0x0EFF,   'Lao'],
        [0x0F00,   0x0FFF,   'Tibetan'],
        [0x1000,   0x109F,   'Myanmar'],
        [0x1100,   0x11FF,   'Hangul jamo'],
        [0x1780,   0x17FF,   'Khmer'],
        [0x1800,   0x18AF,   'Mongolian'],
        [0x19E0,   0x19FF,   'Khmer'],
        [0x1A20,   0x1AAF,   'Tai Tham'],
        [0x1B00,   0x1B7F,   'Balinese'],
        [0x1B80,   0x1BBF,   'Sundanese'],
        [0x1BC0,   0x1BFF,   'Batak'],
        [0x1C00,   0x1C4F,   'Lepcha'],
        [0xA8E0,   0xA8FF,   'Devanagari'],
        [0xA960,   0xA97F,   'Hangul jamo'],
        [0xA980,   0xA9DF,   'Javanese'],
        [0xA9E0,   0xA9FF,   'Myanmar'],
        [0xAA60,   0xAA7F,   'Myanmar'],
        [0xD7B0,   0xD7FF,   'Hangul jamo'],
        [0xFB1D,   0xFB4F,   'Hebrew'],
        [0xFB50,   0xFDFF,   'Arabic'],
        [0xFE70,   0xFEFF,   'Arabic'],
        [0x1_0800, 0x1_0FFF, 'right-to-left'],
        [0x1_1000, 0x1_1FFF, 'Brahmic'],
        [0x1_E800, 0x1_EFFF, 'right-to-left'],
    ];

    private const string PATTERN =
        '/[\x{0590}-\x{08FF}\x{0900}-\x{0FFF}\x{1000}-\x{109F}\x{1100}-\x{11FF}'
            . '\x{1780}-\x{18AF}\x{19E0}-\x{19FF}\x{1A20}-\x{1AAF}\x{1B00}-\x{1C4F}\x{A8E0}-\x{A8FF}\x{A960}-\x{A97F}'
            . '\x{A980}-\x{A9FF}\x{AA60}-\x{AA7F}\x{D7B0}-\x{D7FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}'
            . '\x{10800}-\x{11FFF}\x{1E800}-\x{1EFFF}]/u';

    /**
     * The first character of the text in a script that needs complex shaping, and its script.
     *
     * @return array{0: int, 1: string}|null
     */
    public static function find(string $text): ?array
    {
        $m = [];
        if (1 !== preg_match(self::PATTERN, $text, $m)) {
            return null;
        }
        $codePoint = mb_ord($m[0] ?? '', encoding: 'UTF-8');
        if (false === $codePoint) {
            return null;
        }
        foreach (self::RANGES as [$first, $last, $script]) {
            if ($codePoint >= $first && $codePoint <= $last) {
                return [$codePoint, $script];
            }
        }

        return null;
    }
}
