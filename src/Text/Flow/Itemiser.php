<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Font\FontInterface;
use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Text\Hyphenation\Hyphenator;
use Php2Pdf\Text\Style\TextStyle;

use function array_key_exists;
use function array_slice;
use function array_values;
use function count;
use function explode;
use function mb_ord;
use function mb_str_split;
use function mb_strlen;
use function preg_match;
use function preg_match_all;
use function preg_split;
use function spl_object_id;
use function str_replace;

use const PREG_SPLIT_NO_EMPTY;

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

    /** Spaces of fixed width that allow a break: en, em, three-, four- and six-per-em, punctuation, thin, hair. */
    private const string FIXED_SPACES = '[\x{2002}-\x{2006}\x{2008}-\x{200A}]';

    /** A run of word spaces: glue. */
    private const string GLUE = 'glue';

    /** A hyphenation point: a penalty showing a hyphen. */
    private const string HYPHEN = 'hyphen';

    /** A break that shows nothing. */
    private const string BREAK = 'break';

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
        foreach ($this->byFont($spans) as [$text, $style]) {
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

    /**
     * Spans split where the font changes: each character goes to the first font in its style's
     * font and fallback list that has it, or to the font when none does. Spaces, tabs and line
     * breaks stay with the run they are in.
     *
     * @param list<array{0: string, 1: TextStyle}> $spans
     *
     * @return list<array{0: string, 1: TextStyle}>
     */
    private function byFont(array $spans): array
    {
        $out = [];
        foreach ($spans as [$text, $style]) {
            $fallback = $style->fallback ?? [];
            if ([] === $fallback) {
                $out[] = [$text, $style];
                continue;
            }
            $font   = StyleResolver::fontOf($style);
            $styles = [spl_object_id($font) => $style];
            $run    = '';
            $using  = $font;
            foreach (mb_str_split($text, length: 1, encoding: 'UTF-8') as $char) {
                $choice = $this->fontFor($char, $font, $fallback, $using);
                if ($choice !== $using && '' !== $run) {
                    $out[] = [$run, $styles[spl_object_id($using)] ??= $style->merge(new TextStyle(font: $using))];
                    $run   = '';
                }
                $using = $choice;
                $run   .= $char;
            }
            if ('' !== $run) {
                $out[] = [$run, $styles[spl_object_id($using)] ??= $style->merge(new TextStyle(font: $using))];
            }
        }

        return $out;
    }

    /**
     * The font that sets a character: the current one for spaces and breaks, otherwise the first
     * of the font and its fallbacks that has it, or the font itself when none does.
     *
     * @param list<FontInterface|string> $fallback resolved, so fonts only
     */
    private function fontFor(string $char, FontInterface $font, array $fallback, FontInterface $current): FontInterface
    {
        $codePoint = mb_ord($char, encoding: 'UTF-8');
        if (false === $codePoint || $codePoint <= 0x20 || self::SOFT_HYPHEN === $char) {
            return $current;
        }
        if ($font->hasGlyph($codePoint)) {
            return $font;
        }
        foreach ($fallback as $candidate) {
            if ($candidate instanceof FontInterface && $candidate->hasGlyph($codePoint)) {
                return $candidate;
            }
        }

        return $font;
    }

    /**
     * A word's hyphenation points from the patterns for its language. Punctuation before and after
     * the letters stays attached, so "(typography," hyphenates like "typography".
     *
     * @return list<string>
     */
    private function patternPieces(string $word, TextStyle $style): array
    {
        $hyphenator = $this->hyphenators[$style->language ?? ''] ?? null;
        $parts      = [];
        if (
            null === $hyphenator
            || true !== $style->hyphenate
            || preg_match('/^([^\p{L}]*)(\p{L}+)([^\p{L}]*)$/u', $word, $parts) !== 1
        ) {
            return [$word];
        }
        $lead    = $parts[1] ?? '';
        $letters = $parts[2] ?? '';
        $trail   = $parts[3] ?? '';
        if (mb_strlen($letters, encoding: 'UTF-8') < ($style->minHyphenatedWordLength ?? 6)) {
            return [$word];
        }
        $syllables        = $hyphenator->syllables($letters, $style->hyphenLeftMin ?? 2, $style->hyphenRightMin ?? 2);
        $syllables[0]     = $lead . ($syllables[0] ?? '');
        $last             = count($syllables) - 1;
        $syllables[$last] = ($syllables[$last] ?? '') . $trail;

        return $syllables;
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
            $pair   = StyleResolver::fontOf($style)->shape("{$last->text()}-", $style->textOptions());
            $first  = $pair->glyphs[count($pair->glyphs) - 2] ?? null;
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
     * The glyphs with every ligature that straddles a break between pieces (a hyphenation point,
     * a hard hyphen) set as separate glyphs instead, each kerned towards the character after it.
     *
     * @param list<Glyph>                         $glyphs
     * @param list<array{0: string, 1: ?string}> $pieces
     *
     * @return list<Glyph>
     */
    private function splitLigatures(array $glyphs, array $pieces, string $shaped, TextStyle $style): array
    {
        $boundaries = [];
        $at         = 0;
        foreach ($pieces as [$piece]) {
            $at              += mb_strlen($piece, encoding: 'UTF-8');
            $boundaries[$at] = true;
        }
        $font    = StyleResolver::fontOf($style);
        $options = $style->textOptions()->withLigatures(false);
        $chars   = mb_str_split($shaped, length: 1, encoding: 'UTF-8');
        $out     = [];
        $at      = 0;
        foreach ($glyphs as $glyph) {
            $length    = $glyph->length();
            $straddles = false;
            for ($b = $at + 1; $b < ($at + $length); ++$b) {
                $straddles = $straddles || array_key_exists($b, $boundaries);
            }
            $parts = $straddles
                ? array_slice(
                    $font->shape($glyph->text() . ($chars[$at + $length] ?? ''), $options)->glyphs,
                    offset: 0,
                    length: $length,
                )
                : [$glyph];
            foreach ($parts as $part) {
                $out[] = $part;
            }
            $at += $length;
        }

        return $out;
    }

    /**
     * Items for text with no tab or line break, shaped as one run. A run of word spaces becomes
     * one glue, kerned like its last space towards the next word. Fixed-width spaces (en, em,
     * thin...) are boxes a line may break after; hard hyphens, dashes and slashes are followed by
     * a break that shows no hyphen.
     *
     * @return list<Item>
     */
    private function stretch(string $text, TextStyle $style): array
    {
        $font    = StyleResolver::fontOf($style);
        $matches = [];
        preg_match_all('/[ ]+|' . self::FIXED_SPACES . '|(?:(?!' . self::FIXED_SPACES . ')[^ ])+/u', $text, $matches);
        /** @var list<array{0: string, 1: ?string}> $pieces piece, then the break after it */
        $pieces = [];
        $shaped = '';
        foreach ($matches[0] ?? [] as $chunk) {
            if (' ' === $chunk[0]) {
                $pieces[] = [$chunk, self::GLUE];
                $shaped   .= $chunk;
                continue;
            }
            if (preg_match('/^' . self::FIXED_SPACES . '$/u', $chunk) === 1) {
                $pieces[]  = [$chunk, self::BREAK];
                $codePoint = mb_ord($chunk, encoding: 'UTF-8');
                $shaped    .= false !== $codePoint && $font->hasGlyph($codePoint) ? $chunk : ' ';
                continue;
            }
            foreach ($this->wordPieces($chunk, $style) as $piece) {
                $pieces[] = $piece;
                $shaped   .= $piece[0];
            }
        }
        $glyphs = $this->splitLigatures($font->shape($shaped, $style->textOptions())->glyphs, $pieces, $shaped, $style);

        $items  = [];
        $cursor = 0;
        foreach ($pieces as [$piece, $after]) {
            $length = mb_strlen($piece, encoding: 'UTF-8');
            $slice  = [];
            $count  = 0;
            while ($count < $length && null !== ($glyph = $glyphs[$cursor] ?? null)) {
                $slice[] = $glyph;
                $count   += $glyph->length();
                ++$cursor;
            }
            if (self::GLUE === $after) {
                $first = $slice[0] ?? null;
                $last  = $slice[count($slice) - 1] ?? null;
                if (null !== $first && null !== $last) {
                    $space   = $first->withKerning($last->kerning);
                    $items[] = self::cut(ItemKind::Glue, $style, ' ', [$space]);
                }
                continue;
            }
            if ('' !== $piece) {
                $items[] = self::cut(ItemKind::Box, $style, $piece, $slice);
            }
            if (self::HYPHEN === $after) {
                $items[] = $this->penalty($slice, $style);
            }
            if (self::BREAK === $after) {
                $items[] = new Item(ItemKind::Penalty, $style);
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

    /**
     * A word cut where it may break: after hard hyphens, en dashes and slashes (not before a
     * digit), on both sides of an em dash, and at soft hyphens or, failing those, pattern
     * hyphenation points within each part.
     *
     * @return list<array{0: string, 1: ?string}> pieces, each with the break that follows it
     */
    private function wordPieces(string $word, TextStyle $style): array
    {
        $parts = preg_split(
            '/(?<=[^\s\-\x{2010}\x{2013}\x{2014}\/])(?=\x{2014})|(?<=[\-\x{2010}\x{2013}\/])(?=[^\d\-\x{2010}\x{2013}\x{2014}\/])|(?<=\x{2014})(?=[^\x{2014}])/u',
            $word,
            flags: PREG_SPLIT_NO_EMPTY,
        );
        $parts  = false === $parts || [] === $parts ? [$word] : $parts;
        $pieces = [];
        $last   = count($parts) - 1;
        foreach ($parts as $p => $part) {
            $syllables = explode(self::SOFT_HYPHEN, $part);
            if (1 === count($syllables)) {
                $syllables = $this->patternPieces($part, $style);
            }
            $final = count($syllables) - 1;
            foreach ($syllables as $s => $syllable) {
                $after = $p < $last ? self::BREAK : null;
                if ($s < $final) {
                    $after = self::HYPHEN;
                }
                $pieces[] = [$syllable, $after];
            }
        }

        return $pieces;
    }
}
