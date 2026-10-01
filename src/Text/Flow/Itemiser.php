<?php

declare(strict_types=1);

namespace PhpPdf\Text\Flow;

use PhpPdf\Text\Hyphenation\Hyphenator;
use PhpPdf\Text\Style\TextStyle;

use function count;
use function explode;
use function mb_strlen;
use function preg_match_all;
use function preg_replace;
use function str_replace;

/**
 * Splits styled text into boxes, glue and penalties: words, spaces, tabs, explicit breaks, soft
 * and pattern hyphens, with non-breaking spaces kept inside words.
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

    /**
     * @param list<array{0: string, 1: TextStyle}> $spans text with its resolved style
     *
     * @return list<Item>
     */
    public function itemise(array $spans): array
    {
        $items = [];
        foreach ($spans as [$text, $style]) {
            $text    = str_replace(["\r\n", "\r"], replace: "\n", subject: $text);
            $matches = [];
            preg_match_all('/\n|\t|[ ]+|[^ \n\t]+/u', $text, $matches);
            foreach ($matches[0] ?? [] as $chunk) {
                if ("\n" === $chunk) {
                    $items[] = new Item(ItemKind::LineBreak, $style);
                    continue;
                }
                if ("\t" === $chunk) {
                    $items[] = new Item(ItemKind::Tab, $style);
                    continue;
                }
                if (' ' === $chunk[0]) {
                    $items[] = $this->glue($style);
                    continue;
                }
                foreach ($this->word($chunk, $style) as $item) {
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
     * A word as boxes separated by discretionary penalties at soft hyphens and pattern breaks.
     *
     * @return list<Item>
     */
    private function word(string $word, TextStyle $style): array
    {
        $pieces = explode(self::SOFT_HYPHEN, $word);
        if (1 === count($pieces)) {
            $pieces = $this->patternPieces($word, $style);
        }
        $items  = [];
        $hyphen = null;
        $last   = count($pieces) - 1;
        foreach ($pieces as $i => $piece) {
            if ('' !== $piece) {
                $items[] = self::box($piece, $style);
            }
            if ($i < $last) {
                $hyphen  ??= self::box('-', $style);
                $items[] = new Item(ItemKind::Penalty, $style, '', null, 0.0, $hyphen->run, $hyphen->width);
            }
        }

        return $items;
    }
}
