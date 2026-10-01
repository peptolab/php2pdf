<?php

declare(strict_types=1);

namespace PhpPdf\Text\Flow;

use PhpPdf\Font\Glyph;
use PhpPdf\Font\GlyphRun;
use PhpPdf\Text\Hyphenation\Hyphenator;
use PhpPdf\Text\Style\TextStyle;

use function array_slice;
use function array_values;
use function count;
use function explode;
use function implode;
use function mb_chr;
use function mb_strlen;
use function preg_match_all;
use function preg_replace;
use function str_replace;

/**
 * Splits styled text into boxes, glue and penalties: words, spaces, tabs, explicit breaks, soft
 * and pattern hyphens, with non-breaking spaces kept inside words.
 *
 * Text between tabs and line breaks is shaped as a whole and then cut into items, so kerning
 * holds across word spaces and between the pieces of a hyphenatable word, as it would in a single
 * run. Each glyph's kerning is towards the glyph that follows it in the text; LineBreaker adjusts
 * the last glyph of a line where the line ends at a space or a hyphen instead.
 *
 * @internal
 */
final class Itemiser
{
    private const string SOFT_HYPHEN = "\u{00AD}";

    /** @param array<string, Hyphenator> $hyphenators by language */
    public function __construct(
        private readonly array $hyphenators = [],
    ) {}

    public static function box(string $text, TextStyle $style): Item
    {
        $font = StyleResolver::fontOf($style);
        $run  = $font->shape($text, $style->textOptions());

        return new Item(
            ItemKind::Box,
            $style,
            $text,
            $run,
            $font->width($run, $style->fontSize(), $style->textOptions()),
        );
    }

    public static function glue(TextStyle $style): Item
    {
        $font = StyleResolver::fontOf($style);
        $run  = $font->shape(' ', $style->textOptions());

        return new Item(
            ItemKind::Glue,
            $style,
            ' ',
            $run,
            $font->width($run, $style->fontSize(), $style->textOptions()),
        );
    }

    /** The width in points of a kerning change of $kerning thousandths of an em. */
    public static function kerningWidth(float $kerning, TextStyle $style): float
    {
        return (($kerning / 1000.0) * $style->fontSize() * ($style->horizontalScale ?? 100.0)) / 100.0;
    }

    /**
     * An item for glyphs cut from a shaped run.
     *
     * @param list<Glyph> $glyphs
     */
    private static function cut(ItemKind $kind, TextStyle $style, string $text, array $glyphs): Item
    {
        $font = StyleResolver::fontOf($style);
        $run  = new GlyphRun($glyphs);

        return new Item($kind, $style, $text, $run, $font->width($run, $style->fontSize(), $style->textOptions()));
    }

    /**
     * @param list<array{0: string, 1: TextStyle}> $spans text with its resolved style
     *
     * @return list<Item>
     */
    public function itemise(array $spans): array
    {
        $items = [];
        foreach ($spans as [$text, $style]) {
            $text = str_replace(["\r\n", "\r"], replace: "\n", subject: $text);
            foreach ($this->stretches($text) as $stretch) {
                if ("\n" === $stretch) {
                    $items[] = new Item(ItemKind::LineBreak, $style);
                    continue;
                }
                if ("\t" === $stretch) {
                    $items[] = new Item(ItemKind::Tab, $style);
                    continue;
                }
                foreach ($this->stretch($stretch, $style) as $item) {
                    $items[] = $item;
                }
            }
        }

        return $items;
    }

    /** @return list<string> */
    private function patternPieces(string $word, TextStyle $style): array
    {
        $hyphenator = $this->hyphenators[$style->language ?? ''] ?? null;
        if (null === $hyphenator || true !== $style->hyphenate) {
            return [$word];
        }
        $letters = preg_replace('/[^\p{L}]/u', replacement: '', subject: $word) ?? $word;
        if (mb_strlen($letters, encoding: 'UTF-8') < ($style->minHyphenatedWordLength ?? 6) || $letters !== $word) {
            return [$word];
        }

        return $hyphenator->syllables($word, $style->hyphenLeftMin ?? 2, $style->hyphenRightMin ?? 2);
    }

    /**
     * A hyphenation point: the hyphen, and how the kerning of the last glyph before it changes
     * when the line breaks there (from the next piece to the hyphen).
     *
     * @param list<Glyph> $before the glyphs of the piece before the break
     */
    private function penalty(array $before, TextStyle $style): Item
    {
        $hyphen = self::box('-', $style);
        $last   = $before[count($before) - 1] ?? null;
        $change = 0.0;
        if (null !== $last) {
            $pair = StyleResolver::fontOf($style)->shape(
                mb_chr($last->codePoint, encoding: 'UTF-8') . '-',
                $style->textOptions(),
            );
            $first  = $pair->glyphs[0] ?? null;
            $change = (null === $first ? 0.0 : $first->kerning) - $last->kerning;
        }

        return new Item(
            ItemKind::Penalty,
            $style,
            '',
            null,
            0.0,
            $hyphen->run,
            $hyphen->width + self::kerningWidth($change, $style),
            $change,
        );
    }

    /**
     * Items for text with no tab or line break, shaped as one run. A run of spaces becomes one
     * glue, kerned like its last space towards the next word.
     *
     * @return list<Item>
     */
    private function stretch(string $text, TextStyle $style): array
    {
        $matches = [];
        preg_match_all('/[ ]+|[^ ]+/u', $text, $matches);
        $chunks = $matches[0] ?? [];
        $parts  = [];
        $shaped = '';
        foreach ($chunks as $chunk) {
            if (' ' === $chunk[0]) {
                $parts[] = [$chunk];
                $shaped  .= $chunk;
                continue;
            }
            $pieces = explode(self::SOFT_HYPHEN, $chunk);
            if (1 === count($pieces)) {
                $pieces = $this->patternPieces($chunk, $style);
            }
            $parts[] = $pieces;
            $shaped  .= implode('', $pieces);
        }
        $glyphs = StyleResolver::fontOf($style)->shape($shaped, $style->textOptions())->glyphs;

        $items  = [];
        $offset = 0;
        foreach ($parts as $pieces) {
            if (' ' === ($pieces[0][0] ?? '')) {
                $length = mb_strlen($pieces[0] ?? '', encoding: 'UTF-8');
                $first  = $glyphs[$offset] ?? null;
                $last   = $glyphs[$offset + $length - 1] ?? null;
                $offset += $length;
                if (null === $first || null === $last) {
                    continue;
                }
                $space   = new Glyph($first->id, $first->codePoint, $first->advance, $last->kerning);
                $items[] = self::cut(ItemKind::Glue, $style, ' ', [$space]);
                continue;
            }
            $lastPiece = count($pieces) - 1;
            foreach ($pieces as $i => $piece) {
                $length = mb_strlen($piece, encoding: 'UTF-8');
                $slice  = array_slice($glyphs, $offset, $length);
                $offset += $length;
                if ('' !== $piece) {
                    $items[] = self::cut(ItemKind::Box, $style, $piece, $slice);
                }
                if ($i < $lastPiece) {
                    $items[] = $this->penalty($slice, $style);
                }
            }
        }

        return $items;
    }

    /**
     * The text split at tabs and line breaks, which are returned as single characters.
     *
     * @return list<string>
     */
    private function stretches(string $text): array
    {
        $matches = [];
        preg_match_all('/\n|\t|[^\n\t]+/u', $text, $matches);

        return array_values($matches[0] ?? []);
    }
}
