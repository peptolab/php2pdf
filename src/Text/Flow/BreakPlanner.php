<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Text\Style\Composer;
use Php2Pdf\Text\Style\ParagraphFormat;
use WeakMap;

use function array_key_exists;
use function min;

/**
 * The paragraph composer's plans: for each paragraph, the breaks it chose, kept while the
 * paragraph is laid out at the same widths, so each line of a plan is set as it was planned and a
 * paragraph laid out again is not composed again. A paragraph with forced breaks is planned one
 * stretch at a time, each stretch added to its plan. A flow keeps one planner for all its fits;
 * a plan goes with its paragraph.
 *
 * @internal
 */
final class BreakPlanner
{
    /**
     * @var WeakMap<Paragraph, array{0: string, 1: array<int, LineEnd>}>|null widths, and line start
     *      => line end; made with the first plan
     */
    private ?WeakMap $plans = null;

    /**
     * @var WeakMap<Paragraph, array{0: string, 1: int, 2: int, 3: float}> for the composer's last
     *      failure on a paragraph: widths, the start it scanned from, the tab or word it stopped at,
     *      and the line width that word was wider than (INF for a tab); made with the first
     *      failure
     */
    private ?WeakMap $failures = null;

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
     * it: from the plan of its breaks, made from the line it starts at. A line not starting at a
     * planned break has the stretch from it planned and added; a different frame width starts the
     * plan again. Null when the paragraph is set line by line, or the composer cannot set it.
     */
    public function lineEnd(Paragraph $paragraph, int $start, LineWidths $widths, int $lineNumber): ?LineEnd
    {
        $style    = $paragraph->style;
        $composer = ParagraphFormat::of($style)->composer;
        if (Composer::SingleLine === $composer) {
            return null;
        }
        $key  = $widths->key();
        $plan = $this->plans[$paragraph] ?? null;
        if (null === $plan || $key !== $plan[0] || ! array_key_exists($start, $plan[1])) {
            if ($this->failsAsBefore($paragraph, $start, $widths, $lineNumber, $key)) {
                return null;
            }
            $obstacle = null;
            $limit    = null;
            $ends     = ParagraphComposer::breaks($paragraph, $start, $widths, $lineNumber, $obstacle, $limit);
            if (null === $ends) {
                if (null !== $obstacle && null !== $limit) {
                    $this->failures ??= new WeakMap();
                    $this->failures[$paragraph] = [$key, $start, $obstacle, $limit];
                }

                return null;
            }
            $plan = null === $plan || $key !== $plan[0] ? [$key, []] : $plan;
            $from = $start;
            foreach ($ends as $end) {
                $plan[1][$from] = $end;
                $from           = self::nextStart($paragraph->items, $end);
            }
            $this->plans ??= new WeakMap();
            $this->plans[$paragraph] = $plan;
        }

        return $plan[1][$start] ?? null;
    }

    /**
     * Whether the composer would fail from $start for the reason it last failed on the paragraph,
     * so need not scan again. Its scan from the stored start passed every item before the
     * obstacle (none a forced break or a tab), so a scan from any start up to the obstacle reaches
     * it unless it stops earlier; and the obstacle stops it while the lines are no wider than they
     * were then. Without this, each line of a paragraph with a word wider than its lines would
     * scan again to that word, set line by line.
     */
    private function failsAsBefore(
        Paragraph $paragraph,
        int $start,
        LineWidths $widths,
        int $lineNumber,
        string $key,
    ): bool {
        $failure = $this->failures[$paragraph] ?? null;

        return (
            null !== $failure
                && $key === $failure[0]
                && $start >= $failure[1]
                && $start <= $failure[2]
                && min($widths->available($lineNumber), $widths->available($lineNumber + 1)) <= $failure[3]
        );
    }
}
