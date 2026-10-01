<?php

declare(strict_types=1);

namespace PhpPdf\Canvas;

use PhpPdf\Core\Num;
use PhpPdf\Font\GlyphRun;
use PhpPdf\Font\PdfFontInterface;
use PhpPdf\Font\TextOptions;

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
     * positions, otherwise a TJ array of strings interleaved with kerning, tracking and word
     * spacing adjustments in thousandths of an em.
     */
    public static function operation(
        GlyphRun $run,
        PdfFontInterface $font,
        float $size,
        TextOptions $options,
    ): string {
        $wordAdjust = 0.0 === $options->wordSpacing || $size <= 0.0 ? 0.0 : ($options->wordSpacing / $size) * 1000.0;
        $tracking   = $options->tracking;
        $parts      = [];
        $ids        = [];
        foreach ($run->glyphs as $glyph) {
            $ids[]  = $glyph->id;
            $adjust = $glyph->kerning + $tracking;
            if (0.0 !== $wordAdjust && $glyph->isSpace()) {
                $adjust += $wordAdjust;
            }
            if (0.0 !== $adjust) {
                $parts[] = self::literal($font->encode($ids));
                $parts[] = Num::format(-$adjust, 2);
                $ids     = [];
            }
        }
        if ([] !== $ids) {
            $parts[] = self::literal($font->encode($ids));
        }
        if (1 === count($parts)) {
            return "{$parts[0]} Tj";
        }

        return '[' . implode(' ', $parts) . '] TJ';
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

    /** Bytes as a PDF literal string; carriage returns are escaped so readers do not normalise them. */
    private static function literal(string $bytes): string
    {
        return '(' . strtr($bytes, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r']) . ')';
    }
}
