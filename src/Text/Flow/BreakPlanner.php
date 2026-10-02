<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\Composer;
use WeakMap;

use function array_key_exists;

/**
 * The paragraph composer's plans: for each paragraph, the breaks it chose, kept while the
 * paragraph is laid out at the same widths, so each line of a plan is set as it was planned. A
 * flow keeps one planner for all its fits; a plan goes with its paragraph.
 *
 * @internal
 */
final class BreakPlanner
{
    /** @var WeakMap<Paragraph, array{0: string, 1: array<int, LineEnd>}> widths, and line start => line end */
    private WeakMap $plans;

    public function __construct()
    {
        $this->plans = new WeakMap();
    }

    /**
     * Where the line after an end starts: past a forced break and the glue a line broke at.
     *
     * @param list<Item> $items
     */
    private static function nextStart(array $items, LineEnd $end): int
    {
        $next = $end->forced ? $end->index + 1 : $end->index;
        while (ItemKind::Glue === ($items[$next] ?? null)?->kind) {
            ++$next;
        }

        return $next;
    }

    /**
     * Where the line starting at $start ends by the paragraph composer, when the paragraph uses
     * it: from the plan of its breaks, made from the line it starts at, and made again when a line
     * does not start at a planned break or the frame's width differs. Null when the paragraph is set line by line, or the composer cannot
     * set it.
     */
    public function lineEnd(Paragraph $paragraph, int $start, LineWidths $widths, int $lineNumber): ?LineEnd
    {
        $style    = $paragraph->style;
        $composer = $style->composer
            ?? (Alignment::Justify === $style->alignment ? Composer::Paragraph : Composer::SingleLine);
        if (Composer::SingleLine === $composer) {
            return null;
        }
        $key  = $widths->key();
        $plan = $this->plans[$paragraph] ?? null;
        if (null === $plan || $key !== $plan[0] || ! array_key_exists($start, $plan[1])) {
            $ends = ParagraphComposer::breaks($paragraph, $start, $widths, $lineNumber);
            if (null === $ends) {
                return null;
            }
            $plan = [$key, []];
            $from = $start;
            foreach ($ends as $end) {
                $plan[1][$from] = $end;
                $from           = self::nextStart($paragraph->items, $end);
            }
            $this->plans[$paragraph] = $plan;
        }

        return $plan[1][$start] ?? null;
    }
}
