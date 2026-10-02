<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Text\Hyphenation\Hyphenator;
use Php2Pdf\Text\Style\TextStyle;

use function array_values;
use function count;
use function explode;
use function mb_ord;
use function mb_strlen;
use function preg_match;
use function preg_match_all;
use function preg_split;

use const PREG_SPLIT_NO_EMPTY;

/**
 * Where text may break: at tabs and line breaks, at word spaces and fixed spaces, after hard
 * hyphens, dashes and slashes, and at soft hyphens or, failing those, the hyphenation points of
 * the patterns for the text's language.
 *
 * @internal
 */
final readonly class WordBreaks
{
    public const string SOFT_HYPHEN = "\u{00AD}";

    /** Spaces of fixed width that allow a break: en, em, three-, four- and six-per-em, punctuation, thin, hair. */
    public const string FIXED_SPACES = '[\x{2002}-\x{2006}\x{2008}-\x{200A}]';

    /** A run of word spaces: glue. */
    public const string GLUE = 'glue';

    /** A hyphenation point: a penalty showing a hyphen. */
    public const string HYPHEN = 'hyphen';

    /** A break that shows nothing. */
    public const string BREAK = 'break';

    /** @param array<string, Hyphenator> $hyphenators by language */
    public function __construct(
        private array $hyphenators = [],
    ) {}

    /**
     * The text split at tabs and line breaks, which are returned as single characters.
     *
     * @return list<string>
     */
    public static function stretches(string $text): array
    {
        $matches = [];
        preg_match_all('/\n|\t|[^\n\t]+/u', $text, $matches);

        return array_values($matches[0] ?? []);
    }

    /**
     * The pieces of a stretch, each with the break after it (glue, a break showing nothing, a
     * hyphen, or none), and the text to shape for them: fixed spaces the font lacks become word
     * spaces. Right-to-left words are not hyphenated.
     *
     * @return array{0: list<array{0: string, 1: ?string}>, 1: string}
     */
    public function pieces(string $text, TextStyle $style, int $level): array
    {
        $font    = StyleResolver::fontOf($style);
        $matches = [];
        preg_match_all(
            '/[ ]+|' . WordBreaks::FIXED_SPACES . '|(?:(?!' . WordBreaks::FIXED_SPACES . ')[^ ])+/u',
            $text,
            $matches,
        );
        $pieces = [];
        $shaped = '';
        foreach ($matches[0] ?? [] as $chunk) {
            if (' ' === $chunk[0]) {
                $pieces[] = [$chunk, WordBreaks::GLUE];
                $shaped   .= $chunk;
                continue;
            }
            if (preg_match('/^' . WordBreaks::FIXED_SPACES . '$/u', $chunk) === 1) {
                $pieces[]  = [$chunk, WordBreaks::BREAK];
                $codePoint = mb_ord($chunk, encoding: 'UTF-8');
                $shaped    .= false !== $codePoint && $font->hasGlyph($codePoint) ? $chunk : ' ';
                continue;
            }
            foreach (1 === ($level & 1) ? [[$chunk, null]] : $this->wordPieces($chunk, $style) as $piece) {
                $pieces[] = $piece;
                $shaped   .= $piece[0];
            }
        }

        return [$pieces, $shaped];
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
            $syllables = explode(WordBreaks::SOFT_HYPHEN, $part);
            if (1 === count($syllables)) {
                $syllables = $this->patternPieces($part, $style);
            }
            $final = count($syllables) - 1;
            foreach ($syllables as $s => $syllable) {
                $after = $p < $last ? WordBreaks::BREAK : null;
                if ($s < $final) {
                    $after = WordBreaks::HYPHEN;
                }
                $pieces[] = [$syllable, $after];
            }
        }

        return $pieces;
    }
}
