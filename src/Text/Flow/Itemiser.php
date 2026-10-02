<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\TextDirection;
use Php2Pdf\Text\Hyphenation\Hyphenator;
use Php2Pdf\Text\Style\CharFormat;
use Php2Pdf\Text\Style\TextStyle;

use function array_key_exists;
use function array_reverse;
use function array_slice;
use function count;
use function implode;
use function mb_str_split;
use function mb_strlen;
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
    private readonly WordBreaks $breaks;

    /** @param array<string, Hyphenator> $hyphenators by language */
    public function __construct(array $hyphenators = [])
    {
        $this->breaks = new WordBreaks($hyphenators);
    }

    /**
     * Text cut where its bidi level changes, each piece with its level.
     *
     * @param list<int> $levels
     *
     * @return list<array{0: string, 1: int}>
     */
    private static function levelRuns(string $text, array $levels): array
    {
        if ([] === $levels) {
            return [[$text, 0]];
        }
        $runs  = [];
        $run   = '';
        $level = $levels[0];
        foreach (mb_str_split($text, length: 1, encoding: 'UTF-8') as $k => $char) {
            $next = $levels[$k] ?? $level;
            if ($next !== $level && '' !== $run) {
                $runs[] = [$run, $level];
                $run    = '';
            }
            $level = $next;
            $run   .= $char;
        }
        $runs[] = [$run, $level];

        return $runs;
    }

    /**
     * The items of one piece of a stretch: a run of word spaces as one glue, kerned like its last
     * space towards the next word; otherwise a box, and the break after it.
     *
     * @param list<Glyph> $glyphs the piece's glyphs, in logical order
     *
     * @return list<Item>
     */
    private static function pieceItems(string $piece, ?string $after, array $glyphs, TextStyle $style): array
    {
        if (WordBreaks::GLUE === $after) {
            $first = $glyphs[0] ?? null;
            $last  = $glyphs[count($glyphs) - 1] ?? null;

            return (
                null === $first || null === $last
                    ? []
                    : [ItemFactory::cut(ItemKind::Glue, $style, ' ', [$first->withKerning($last->kerning)])]
            );
        }
        $items = '' === $piece ? [] : [ItemFactory::cut(ItemKind::Box, $style, $piece, $glyphs)];
        if (WordBreaks::HYPHEN === $after) {
            $items[] = ItemFactory::penalty($glyphs, $style);
        }
        if (WordBreaks::BREAK === $after) {
            $items[] = new Item(ItemKind::Penalty, $style);
        }

        return $items;
    }

    /**
     * @param list<array{0: string, 1: TextStyle}> $spans  text with its resolved style
     * @param list<int>                            $levels the bidi level of each character of the
     *                                                     spans' text; none for left-to-right text
     *
     * @return list<Item>
     */
    public function itemise(array $spans, array $levels = []): array
    {
        $items  = [];
        $offset = 0;
        foreach (FontRuns::split($spans) as [$text, $style]) {
            $text = str_replace(["\r\n", "\r"], replace: "\n", subject: $text);
            foreach (WordBreaks::stretches($text) as $stretch) {
                $length = mb_strlen($stretch, encoding: 'UTF-8');
                $level  = $levels[$offset] ?? 0;
                $offset += $length;
                if ("\n" === $stretch || "\t" === $stretch) {
                    $items[] = new Item("\n" === $stretch ? ItemKind::LineBreak : ItemKind::Tab, $style, level: $level);
                    continue;
                }
                foreach (self::levelRuns($stretch, array_slice($levels, $offset - $length, $length)) as [
                    $run,
                    $runLevel,
                ]) {
                    foreach ($this->stretch($run, $style, $runLevel) as $item) {
                        $items[] = 0 === $runLevel ? $item : $item->withLevel($runLevel);
                    }
                }
            }
        }

        return $items;
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
        $options = CharFormat::of($style)->textOptions->withLigatures(false);
        $chars   = mb_str_split($shaped, length: 1, encoding: 'UTF-8');
        $out     = [];
        foreach ($glyphs as $glyph) {
            $from      = $glyph->cluster;
            $to        = $from + $glyph->length();
            $straddles = false;
            for ($b = $from + 1; $b < $to; ++$b) {
                $straddles = $straddles || array_key_exists($b, $boundaries);
            }
            if (! $straddles) {
                $out[] = $glyph;
                continue;
            }
            $text = implode('', array_slice($chars, $from, $to - $from)) . ($chars[$to] ?? '');
            foreach ($font->shape($text, $options)->glyphs as $part) {
                if ($part->cluster >= ($to - $from)) {
                    break;
                }
                $out[] = $part->withCluster($from + $part->cluster);
            }
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
    private function stretch(string $text, TextStyle $style, int $level = 0): array
    {
        $rtl  = 1 === ($level & 1);
        $font = StyleResolver::fontOf($style);
        [$pieces, $shaped] = $this->breaks->pieces($text, $style, $level);
        $options = CharFormat::of($style)->textOptions
            ->withDirection($rtl ? TextDirection::RightToLeft : TextDirection::LeftToRight);
        $shapedRun = $font->shape($shaped, $options)->glyphs;
        $glyphs    = $rtl ? array_reverse($shapedRun) : $this->splitLigatures($shapedRun, $pieces, $shaped, $style);

        $items  = [];
        $cursor = 0;
        $end    = 0;
        foreach ($pieces as [$piece, $after]) {
            $end   += mb_strlen($piece, encoding: 'UTF-8');
            $slice = [];
            while (null !== ($glyph = $glyphs[$cursor] ?? null) && $glyph->cluster < $end) {
                $slice[] = $glyph;
                ++$cursor;
            }
            foreach (self::pieceItems($piece, $after, $rtl ? array_reverse($slice) : $slice, $style) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
