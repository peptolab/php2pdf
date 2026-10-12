<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\ParagraphFormat;
use Php2Pdf\Text\Style\TextStyle;
use WeakMap;

use function array_slice;
use function count;
use function end;
use function pack;

/**
 * Breaks a paragraph into lines for a given width, one line at a time so a box can stop part
 * way: where each line ends, by the paragraph composer or greedily, with the indents and the
 * alignment it is set with. A word wider than the line is split between characters.
 *
 * @internal
 */
final class LineBreaker
{
    /** How many frame widths and levels a style keeps setups for before they start over. */
    private const int SETUPS = 64;

    /**
     * What every line of a paragraph shares, by its style and then by the frame width and its
     * bidi level, kept while the style lives: its line widths, its alignments for its lines and
     * its last line, and where its first line and its other lines start and how wide they are
     * (the line widths tell line 0 from the rest only). Paragraphs mostly share a few styles, so
     * this is worked out once for many paragraphs, and nothing is kept for each.
     *
     * @var WeakMap<TextStyle, array<string, array{0: LineWidths, 1: Alignment, 2: Alignment, 3: array{0: float, 1: float}, 4: array{0: float, 1: float}}>>|null
     */
    private static ?WeakMap $setups = null;

    public function __construct(
        private readonly BreakPlanner $planner,
    ) {}

    /**
     * The paragraph's alignments: for its lines, by default its direction's start edge, and for
     * its last line, which justified text sets with the last-line alignment.
     *
     * @return array{0: Alignment, 1: Alignment}
     */
    private static function alignments(Paragraph $paragraph): array
    {
        $format = ParagraphFormat::of($paragraph->style);

        return [$format->alignment($paragraph->level), $format->lastLineAlignment($paragraph->level)];
    }

    /**
     * How many items from $offset the line takes: up to its end, and a forced line break with it.
     * A column or page break stays for the layout, which stops the box there.
     *
     * @param list<Item> $items
     */
    private static function consumed(LineEnd $end, array $items, int $offset): int
    {
        $lineBreak = $end->forced && ItemKind::LineBreak === ($items[$end->index] ?? null)?->kind;

        return $end->index - $offset + ($lineBreak ? 1 : 0);
    }

    /**
     * Where a line starts: past the glue left over from the break before it.
     *
     * @param list<Item> $items
     */
    private static function firstItem(array $items, int $offset): int
    {
        $start = $offset;
        while (ItemKind::Glue === ($items[$start] ?? null)?->kind) {
            ++$start;
        }

        return $start;
    }

    /**
     * What every line of a paragraph set in a frame of $width shares.
     *
     * @return array{0: LineWidths, 1: Alignment, 2: Alignment, 3: array{0: float, 1: float}, 4: array{0: float, 1: float}}
     */
    private static function setup(Paragraph $paragraph, float $width): array
    {
        self::$setups ??= new WeakMap();
        $style        = $paragraph->style;
        /** @var array<string, array{0: LineWidths, 1: Alignment, 2: Alignment, 3: array{0: float, 1: float}, 4: array{0: float, 1: float}}> $known */
        $known = self::$setups[$style] ?? [];
        $key   = pack('e', $width) . $paragraph->level;
        $setup = $known[$key] ?? null;
        if (null !== $setup) {
            return $setup;
        }
        [$body, $lastLine] = self::alignments($paragraph);
        $widths = new LineWidths($width, $style);
        $setup  = [
            $widths,
            $body,
            $lastLine,
            [$widths->left(0), $widths->available(0)],
            [$widths->left(1), $widths->available(1)],
        ];
        if (count($known) >= self::SETUPS) {
            $known = [];
        }
        $known[$key]          = $setup;
        self::$setups[$style] = $known;

        return $setup;
    }

    /**
     * Breaks the next line from the paragraph's items starting at $offset; null at the end.
     *
     * @param int $lineNumber 0 for the paragraph's first line, which takes the first-line indent
     */
    public function nextLine(
        Paragraph $paragraph,
        int $offset,
        float $width,
        int $lineNumber,
        int $carry = 0,
    ): ?Line {
        $style = $paragraph->style;
        $items = $paragraph->items;
        $count = count($items);
        if ($offset >= $count) {
            return null;
        }
        $items = 0 === $carry ? $items : BoxSplit::resume($items, $offset, $carry);
        [$widths, $body, $lastLine, $first, $rest] = self::setup($paragraph, $width);
        [$left, $available] = 0 === $lineNumber ? $first : $rest;
        $start = self::firstItem($items, $offset);
        $end   = (0 === $carry ? $this->planner->lineEnd($paragraph, $start, $widths, $lineNumber) : null)
            ?? GreedyBreaker::lineEnd($items, $style, $start, $available);
        [$items, $consumed] = null === $end->head
            ? [$items, [self::consumed($end, $items, $offset), 0]]
            : BoxSplit::line($items, $end, $offset, $carry);
        $lineItems = array_slice($items, $start, $end->index - $start);
        $last      = 0 === $consumed[1] && (($end->index + ($end->forced ? 1 : 0)) >= $count || $end->forced);

        if (ItemKind::Glue === ($items[$end->index] ?? null)?->kind) {
            $lineItems = LineAssembler::rekernLast($lineItems, null);
        }

        return LineAssembler::line(
            $lineItems,
            $end->hyphen,
            $style,
            [$last ? $lastLine : $body, $paragraph->level],
            $left,
            $available,
            $consumed,
            $last,
        );
    }
}
