<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Font\Bidi\Bidi;
use Php2Pdf\Font\Bidi\BidiRuns;
use Php2Pdf\Text\Style\ParagraphFormat;
use Php2Pdf\Text\Style\TextStyle;

use function array_slice;
use function count;
use function mb_ord;
use function mb_str_split;
use function mb_strlen;
use function str_replace;

/**
 * Makes paragraphs from their sources: each paragraph's style resolved, its bidi levels found,
 * its spans itemised and its breaks placed between them.
 *
 * @internal
 */
final readonly class ParagraphFactory
{
    public function __construct(
        private StyleResolver $resolver,
        private TextStyle $base,
        private Itemiser $itemiser,
    ) {}

    /**
     * The bidi levels of a paragraph's characters and the paragraph level, when its text has
     * right-to-left characters or its style makes it right to left; none and 0 otherwise.
     *
     * @param list<array{0: string, 1: TextStyle}> $spans
     *
     * @return array{0: list<int>, 1: int}
     */
    private static function bidiLevels(array $spans, TextStyle $style): array
    {
        $text = '';
        foreach ($spans as [$spanText]) {
            $text .= $spanText;
        }
        $direction = ParagraphFormat::of($style)->direction;
        if (! BidiRuns::needed($text, $direction)) {
            return [[], 0];
        }
        $codePoints = [];
        foreach (mb_str_split($text, length: 1, encoding: 'UTF-8') as $char) {
            $codePoints[] = (int) mb_ord($char, encoding: 'UTF-8');
        }
        $paragraph = Bidi::paragraph($codePoints, $direction->level());

        return [$paragraph->levels(), $paragraph->level];
    }

    /**
     * @param list<ParagraphSource> $sources
     *
     * @return list<Paragraph>
     *
     * @throws IoException
     * @throws ParseException
     */
    public function paragraphs(array $sources): array
    {
        $paragraphs = [];
        foreach ($sources as $source) {
            $style   = $this->resolver->resolve($this->base, $source->style);
            $spans   = $source->spans;
            $items   = [];
            $breakAt = [];
            foreach ($source->breaks as [$index, $kind]) {
                $breakAt[$index][] = $kind;
            }
            foreach ($spans as $k => [$spanText, $spanStyle]) {
                $spans[$k] = [str_replace(["\r\n", "\r"], replace: "\n", subject: $spanText), $spanStyle];
            }
            [$levels, $level] = self::bidiLevels($spans, $style);
            $offset = 0;
            foreach ($spans as $i => $span) {
                foreach ($breakAt[$i] ?? [] as $kind) {
                    $items[] = $kind instanceof Item
                        ? $kind->withLevel($level)
                        : new Item($kind, $style, level: $level);
                }
                $length = mb_strlen($span[0], encoding: 'UTF-8');
                foreach ($this->itemiser->itemise([$span], array_slice($levels, $offset, $length)) as $item) {
                    $items[] = $item;
                }
                $offset += $length;
            }
            foreach ($breakAt[count($spans)] ?? [] as $kind) {
                $items[] = $kind instanceof Item ? $kind->withLevel($level) : new Item($kind, $style, level: $level);
            }
            $paragraphs[] = new Paragraph($style, $items, $level);
        }

        return $paragraphs;
    }
}
