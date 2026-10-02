<?php

declare(strict_types=1);

namespace Php2Pdf\Canvas;

use Php2Pdf\Core\Num;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Font\PdfFontInterface;
use Php2Pdf\Font\TextOptions;

use function count;
use function implode;
use function in_array;
use function strtr;

/**
 * The text-showing operators for glyph runs: strings of glyph codes with kerning, tracking and
 * word spacing adjustments.
 *
 * @internal
 */
final class TextOperators
{
    /**
     * The text-showing operation for a run: one Tj string when nothing adjusts the glyph
     * positions, otherwise a TJ array of strings interleaved with kerning, tracking, word spacing
     * and offset adjustments in thousandths of an em. Glyphs raised or lowered by an offset are
     * shown with the text rise set for them and restored after.
     */
    public static function operation(
        GlyphRun $run,
        PdfFontInterface $font,
        float $size,
        TextOptions $options,
    ): string {
        $wordAdjust = 0.0 === $options->wordSpacing || $size <= 0.0 ? 0.0 : ($options->wordSpacing / $size) * 1000.0;
        $segments   = [];
        $parts      = [];
        $ids        = [];
        $rise       = null;
        $pending    = 0.0;
        foreach ($run->glyphs as $glyph) {
            if (null !== $rise && $glyph->yOffset !== $rise) {
                $segments[] = [$rise ?? 0.0, self::flush($parts, $ids, $font)];
                $parts      = [];
                $ids        = [];
            }
            $rise = $glyph->yOffset;
            $move = $pending + $glyph->xOffset;
            if (0.0 !== $move) {
                $parts   = self::flush($parts, $ids, $font);
                $ids     = [];
                $parts[] = Num::format(-$move, 2);
            }
            $ids[]   = $glyph->id;
            $pending = $glyph->kerning + $options->tracking - $glyph->xOffset
            + (0.0 !== $wordAdjust && $glyph->isSpace() ? $wordAdjust : 0.0);
        }
        $parts = self::flush($parts, $ids, $font);
        if (0.0 !== $pending) {
            $parts[] = Num::format(-$pending, 2);
        }
        $segments[] = [$rise ?? 0.0, $parts];
        $ops        = [];
        foreach ($segments as [$offset, $segment]) {
            $show  = 1 === count($segment) ? "{$segment[0]} Tj" : '[' . implode(' ', $segment) . '] TJ';
            $ops[] = 0.0 === $offset
                ? $show
                : Num::format($options->rise + (($offset / 1000.0) * $size))
                . " Ts {$show} "
                . Num::format($options->rise)
                . ' Ts';
        }

        return implode(' ', $ops);
    }

    /**
     * The show operators for a run, with the glyphs at the $hidden positions shown invisibly
     * (render mode 3) and $mode, the current render mode, restored after them.
     *
     * @param list<int> $hidden
     */
    public static function show(
        GlyphRun $run,
        PdfFontInterface $font,
        float $size,
        TextOptions $options,
        array $hidden,
        string $mode,
    ): string {
        if ([] === $hidden) {
            return self::operation($run, $font, $size, $options);
        }
        $parts   = [];
        $span    = [];
        $visible = true;
        foreach ($run->glyphs as $index => $glyph) {
            $show = ! in_array($index, $hidden, strict: true);
            if ($show !== $visible && [] !== $span) {
                $parts[] =
                    ($visible ? '' : '3 Tr ')
                    . self::operation(new GlyphRun($span), $font, $size, $options)
                    . ($visible ? '' : " {$mode}");
                $span = [];
            }
            $visible = $show;
            $span[]  = $glyph;
        }
        $parts[] =
            ($visible ? '' : '3 Tr ')
            . self::operation(new GlyphRun($span), $font, $size, $options)
            . ($visible ? '' : " {$mode}");

        return implode(' ', $parts);
    }

    /**
     * The parts with the glyphs collected so far as one string.
     *
     * @param list<string> $parts
     * @param list<int>    $ids
     *
     * @return list<string>
     */
    private static function flush(array $parts, array $ids, PdfFontInterface $font): array
    {
        if ([] !== $ids) {
            $parts[] = self::literal($font->encode($ids));
        }

        return $parts;
    }

    /** Bytes as a PDF literal string; carriage returns are escaped so readers do not normalise them. */
    private static function literal(string $bytes): string
    {
        return '(' . strtr($bytes, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r']) . ')';
    }
}
