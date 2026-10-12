<?php

declare(strict_types=1);

namespace Php2Pdf\Canvas;

use Php2Pdf\Core\Num;
use Php2Pdf\Core\Serializer;
use Php2Pdf\Core\TextString;
use Php2Pdf\Font\CoreFont;
use Php2Pdf\Font\Font;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Font\PdfFontInterface;
use Php2Pdf\Font\TextDirection;
use Php2Pdf\Font\TextOptions;
use WeakMap;

use function array_reverse;
use function count;
use function implode;
use function in_array;
use function pack;
use function strtr;

/**
 * The text-showing operators for glyph runs: strings of glyph codes with kerning, tracking and
 * word spacing adjustments.
 *
 * @internal
 */
final class TextOperators
{
    /** How many formatted adjustments are remembered before the memo starts over. */
    private const int ADJUSTMENTS = 4096;

    /**
     * Per font, each glyph's code escaped for a literal string. Only for the fonts whose encoding
     * is glyph by glyph (a fixed number of bytes per glyph), so that the codes of a run are the
     * cached codes of its glyphs, one after another.
     *
     * @var WeakMap<PdfFontInterface, array<int, string>>|null
     */
    private static ?WeakMap $codes = null;

    /** @var array<string, string> the exact bits of a move => the TJ adjustment for it */
    private static array $adjustments = [];

    /**
     * Text operations wrapped in a span whose ActualText is the run's text, in reading order:
     * for text whose glyphs the font's ToUnicode map cannot read back.
     */
    public static function actualText(GlyphRun $run, TextOptions $options, string $operations): string
    {
        $glyphs = TextDirection::RightToLeft === $options->direction ? array_reverse($run->glyphs) : $run->glyphs;
        $text   = '';
        foreach ($glyphs as $glyph) {
            if ($glyph->codePoint < 0 && [] === $glyph->components) {
                continue;
            }
            $text .= $glyph->text();
        }

        return (
            '/Span <</ActualText ' . Serializer::literalString(TextString::encode($text)) . ">> BDC {$operations} EMC"
        );
    }

    /**
     * The TJ adjustment for a move by $move thousandths of an em: the opposite number, as TJ
     * moves the other way. Runs repeat a few kerning and spacing values, so the formatted numbers
     * are remembered, keyed by the move's exact bits.
     */
    public static function adjustment(float $move): string
    {
        $key   = pack('e', $move);
        $known = self::$adjustments[$key] ?? null;
        if (null !== $known) {
            return $known;
        }
        if (count(self::$adjustments) >= self::ADJUSTMENTS) {
            self::$adjustments = [];
        }
        $adjustment              = Num::format(-$move, 2);
        self::$adjustments[$key] = $adjustment;

        return $adjustment;
    }

    /**
     * Bytes escaped for a PDF literal string; carriage returns are escaped so readers do not
     * normalise them. The escaping goes byte by byte, so escaped glyph codes, one after another,
     * are the escaped codes of the glyphs together.
     */
    public static function escape(string $bytes): string
    {
        return strtr($bytes, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)', "\r" => '\\r']);
    }

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
        if (! $font instanceof Font && ! $font instanceof CoreFont) {
            return self::general($run, $font, $size, $options, $wordAdjust);
        }
        self::$codes ??= new WeakMap();
        $codes       = self::$codes[$font] ?? [];
        $operation   = self::plain($run, $font, $options->tracking, $wordAdjust, $codes)
            ?? self::general($run, $font, $size, $options, $wordAdjust, $codes);
        self::$codes[$font] = $codes;

        return $operation;
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
     * The operation for a run in general: glyphs may be moved by offsets, and raised or lowered,
     * which splits the run into segments, each shown at its own rise. The glyph codes come from
     * $codes, the font's cached codes, when it has them; this path is rare enough not to add to them.
     *
     * @param array<int, string>|null $codes
     */
    private static function general(
        GlyphRun $run,
        PdfFontInterface $font,
        float $size,
        TextOptions $options,
        float $wordAdjust,
        ?array $codes = null,
    ): string {
        $tracking = $options->tracking;
        $segments = [];
        $parts    = [];
        $ids      = [];
        $rise     = [] === $run->glyphs ? 0.0 : $run->glyphs[0]->yOffset;
        $pending  = 0.0;
        foreach ($run->glyphs as $glyph) {
            $xOffset = $glyph->xOffset;
            $yOffset = $glyph->yOffset;
            $move    = $pending + $xOffset;
            if (($yOffset !== $rise || 0.0 !== $move) && [] !== $ids) {
                $parts[] = self::literal($font, $ids, $codes);
                $ids     = [];
            }
            if ($yOffset !== $rise) {
                $segments[] = [$rise, $parts];
                $parts      = [];
                $rise       = $yOffset;
            }
            if (0.0 !== $move) {
                $parts[] = self::adjustment($move);
            }
            $ids[]   = $glyph->id;
            $pending = $glyph->kerning + $tracking - $xOffset;
            if (0.0 !== $wordAdjust && (0x20 === $glyph->codePoint || 0xA0 === $glyph->codePoint)) {
                $pending += $wordAdjust;
            }
        }
        if ([] !== $ids) {
            $parts[] = self::literal($font, $ids, $codes);
        }
        if (0.0 !== $pending) {
            $parts[] = self::adjustment($pending);
        }
        $segments[] = [$rise, $parts];

        return self::segments($segments, $size, $options);
    }

    /**
     * Glyphs as a PDF literal string, built from the font's cached codes when it has them.
     *
     * @param list<int>               $ids
     * @param array<int, string>|null $codes
     */
    private static function literal(PdfFontInterface $font, array $ids, ?array $codes): string
    {
        if (null === $codes) {
            return '(' . self::escape($font->encode($ids)) . ')';
        }
        $str = '';
        foreach ($ids as $id) {
            $str .= $codes[$id] ?? self::escape($font->encode([$id]));
        }

        return "({$str})";
    }

    /**
     * The operation for a run with no glyph offsets, or null when a glyph has one: one string,
     * and an adjustment only where the previous glyph's kerning, tracking or word spacing left
     * one. The glyph codes come from $codes, the font's cached codes, and new ones are added.
     *
     * @param array<int, string> $codes
     */
    private static function plain(
        GlyphRun $run,
        PdfFontInterface $font,
        float $tracking,
        float $wordAdjust,
        array &$codes,
    ): ?string {
        $parts   = [];
        $str     = '';
        $pending = 0.0;
        foreach ($run->glyphs as $glyph) {
            if (0.0 !== $glyph->xOffset || 0.0 !== $glyph->yOffset) {
                return null;
            }
            // Every glyph code is at least one byte, so an empty string has no glyphs.
            if (0.0 !== $pending && '' !== $str) {
                $parts[] = "({$str})";
                $str     = '';
            }
            if (0.0 !== $pending) {
                $parts[] = self::adjustment($pending);
            }
            $id         = $glyph->id;
            $codes[$id] ??= self::escape($font->encode([$id]));
            $str        .= $codes[$id];
            $pending    = $glyph->kerning + $tracking;
            if (0.0 !== $wordAdjust && (0x20 === $glyph->codePoint || 0xA0 === $glyph->codePoint)) {
                $pending += $wordAdjust;
            }
        }
        if ('' !== $str) {
            $parts[] = "({$str})";
        }
        if (0.0 !== $pending) {
            $parts[] = self::adjustment($pending);
        }

        return 1 === count($parts) ? "{$parts[0]} Tj" : '[' . implode(' ', $parts) . '] TJ';
    }

    /**
     * Each segment's strings and adjustments shown, at its rise when it has one.
     *
     * @param list<array{0: float, 1: list<string>}> $segments
     */
    private static function segments(array $segments, float $size, TextOptions $options): string
    {
        $ops = [];
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
}
