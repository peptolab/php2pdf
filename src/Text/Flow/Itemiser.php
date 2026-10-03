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
use function pack;
use function spl_object_id;
use function str_replace;
use function strlen;

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

    /** @var array<string, Item> items already made, by what they are made of; see pieceItems() */
    private array $interned = [];

    /** @param array<string, Hyphenator> $hyphenators by language */
    public function __construct(array $hyphenators = [])
    {
        $this->breaks = new WordBreaks($hyphenators);
    }

    /**
     * A plain glyph as part of a key, its kerning bit for bit. Its cluster is left out: it only
     * places the glyph in the text being itemised, and items are cut after that is done.
     */
    private static function glyphKey(Glyph $glyph): string
    {
        return "\0{$glyph->id}:{$glyph->codePoint}:{$glyph->advance}:" . pack('e', $glyph->kerning);
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

    /** Whether a glyph is told apart from others by its id, code point, advance and kerning alone. */
    private static function plain(Glyph $glyph): bool
    {
        return [] === $glyph->components && 0.0 === $glyph->xOffset && 0.0 === $glyph->yOffset;
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
                    $kind                 = "\n" === $stretch ? ItemKind::LineBreak : ItemKind::Tab;
                    $key                  = $kind->name . spl_object_id($style) . ":{$level}";
                    $this->interned[$key] ??= new Item($kind, $style, level: $level);
                    $items[]              = $this->interned[$key];
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
     * The box of a word, shared with every identical word before it.
     *
     * @param list<Glyph> $glyphs
     */
    private function box(string $piece, array $glyphs, TextStyle $style, int $level): Item
    {
        if (0 !== $level) {
            return ItemFactory::cut(ItemKind::Box, $style, $piece, $glyphs);
        }
        $key = 'B' . spl_object_id($style) . ':' . strlen($piece) . ":{$piece}";
        foreach ($glyphs as $glyph) {
            if (! self::plain($glyph)) {
                return ItemFactory::cut(ItemKind::Box, $style, $piece, $glyphs);
            }
            $key .= self::glyphKey($glyph);
        }

        return $this->interned[$key] ??= ItemFactory::cut(ItemKind::Box, $style, $piece, $glyphs);
    }

    /**
     * The items of one piece of a stretch: a run of word spaces as one glue, kerned like its last
     * space towards the next word; otherwise a box, and the break after it.
     *
     * Items are immutable and nothing tells them apart by identity, so identical ones are shared:
     * a text's spaces, breaks and repeated words take memory once each, not once per occurrence.
     * Glyphs with marks, ligature components or offsets, and right-to-left or embedded pieces,
     * are left unshared.
     *
     * @param list<Glyph> $glyphs the piece's glyphs, in logical order
     *
     * @return list<Item>
     */
    private function pieceItems(string $piece, ?string $after, array $glyphs, TextStyle $style, int $level): array
    {
        if (WordBreaks::GLUE === $after) {
            $first = $glyphs[0] ?? null;
            $last  = $glyphs[count($glyphs) - 1] ?? null;
            if (null === $first || null === $last) {
                return [];
            }
            $glyph = $first->kerning === $last->kerning ? $first : $first->withKerning($last->kerning);
            if (! self::plain($glyph)) {
                return [ItemFactory::cut(ItemKind::Glue, $style, ' ', [$glyph])];
            }
            $key = 'G' . spl_object_id($style) . self::glyphKey($glyph);

            return [$this->interned[$key] ??= ItemFactory::cut(ItemKind::Glue, $style, ' ', [$glyph])];
        }
        $items = '' === $piece ? [] : [$this->box($piece, $glyphs, $style, $level)];
        if (WordBreaks::HYPHEN === $after) {
            $last = $glyphs[count($glyphs) - 1] ?? null;
            $key  = 'H'
            . spl_object_id($style)
            . (null === $last ? '' : "\0{$last->text()}" . pack('e', $last->kerning));
            $this->interned[$key] ??= ItemFactory::penalty($glyphs, $style);
            $items[]              = $this->interned[$key];
        }
        if (WordBreaks::BREAK === $after) {
            $key                  = 'P' . spl_object_id($style);
            $this->interned[$key] ??= new Item(ItemKind::Penalty, $style);
            $items[]              = $this->interned[$key];
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
            foreach ($this->pieceItems(
                $piece,
                $after,
                $rtl ? array_reverse($slice) : $slice,
                $style,
                $level,
            ) as $item) {
                $items[] = $item;
            }
        }

        return $items;
    }
}
