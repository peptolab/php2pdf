<?php

declare(strict_types=1);

namespace Php2Pdf\Font\Bidi;

use Php2Pdf\Font\FontInterface;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Font\TextDirection;
use Php2Pdf\Font\TextOptions;

use function count;
use function max;
use function mb_chr;
use function mb_ord;
use function mb_str_split;
use function min;
use function preg_match;

/**
 * A line of text in visual order: its runs of one direction, each shaped in its direction, left
 * to right as the Unicode bidi algorithm orders them. Text with no right-to-left characters or
 * bidi controls, on a line that is not right to left, is one left-to-right run.
 *
 * @internal
 */
final class BidiRuns
{
    /** Right-to-left scripts and the bidi controls and marks; a line with none needs no reordering. */
    private const string BIDI = '/[\x{0590}-\x{08FF}\x{FB1D}-\x{FDFF}\x{FE70}-\x{FEFF}\x{200F}\x{202A}-\x{202E}\x{2066}-\x{2069}\x{10800}-\x{10FFF}\x{1E800}-\x{1EFFF}]/u';

    /** Whether text needs the bidi algorithm on a line of the given direction. */
    public static function needed(string $text, TextDirection $direction): bool
    {
        return TextDirection::RightToLeft === $direction || 1 === preg_match(self::BIDI, $text);
    }

    /**
     * The runs of a line in visual order, left to right: each run's text (in logical order) and
     * whether it is right to left.
     *
     * @return list<array{0: string, 1: bool}>
     */
    public static function of(string $text, TextDirection $direction): array
    {
        $codePoints = [];
        foreach (mb_str_split($text, length: 1, encoding: 'UTF-8') as $char) {
            $codePoints[] = (int) mb_ord($char, encoding: 'UTF-8');
        }
        $count = count($codePoints);
        if (0 === $count) {
            return [];
        }
        $paragraph = Bidi::paragraph($codePoints, $direction->level());
        $levels    = $paragraph->lineLevels(0, $count);
        /** @var list<array{0: int, 1: int, 2: int}> $runs first and last index, and level */
        $runs = [];
        /** @var array{0: int, 1: int, 2: int}|null $current */
        $current = null;
        foreach ($paragraph->visualOrder(0, $count) as $i) {
            $level = $levels[$i] ?? 0;
            if (null !== $current && $current[2] === $level && ($i === ($current[1] + 1) || $i === ($current[0] - 1))) {
                $current = [min($current[0], $i), max($current[1], $i), $level];
                continue;
            }
            if (null !== $current) {
                $runs[] = $current;
            }
            $current = [$i, $i, $level];
        }
        if (null !== $current) {
            $runs[] = $current;
        }
        $out = [];
        foreach ($runs as [$first, $last, $level]) {
            $run = '';
            for ($i = $first; $i <= $last; ++$i) {
                $run .= mb_chr($codePoints[$i] ?? 0x20, encoding: 'UTF-8');
            }
            $out[] = [$run, 1 === ($level & 1)];
        }

        return $out;
    }

    /** The line shaped run by run, in visual order. */
    public static function shape(FontInterface $font, string $text, TextOptions $options): GlyphRun
    {
        if (! self::needed($text, $options->direction)) {
            return $font->shape($text, $options);
        }
        $glyphs = [];
        foreach (self::of($text, $options->direction) as [$run, $rtl]) {
            $direction = $rtl ? TextDirection::RightToLeft : TextDirection::LeftToRight;
            foreach ($font->shape($run, $options->withDirection($direction))->glyphs as $glyph) {
                $glyphs[] = $glyph;
            }
        }

        return new GlyphRun($glyphs);
    }
}
