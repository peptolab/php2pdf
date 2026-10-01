<?php

declare(strict_types=1);

namespace PhpPdf\Table;

use PhpPdf\Text\FlowResult;

use function array_sum;
use function max;
use function min;

/**
 * Decides which rows go into a box of a given height: headers first, then body rows from the
 * cursor, groups kept together when they can be, and a row cut when it is alone and too tall;
 * footer rows follow the last body row, their height reserved in every box.
 *
 * @internal
 */
final class Planner
{
    private const float EPSILON = 1.0e-6;

    public function __construct(
        private readonly Layout $layout,
        private readonly Grid $headers,
        private readonly Grid $body,
        private readonly TableOptions $options,
        private readonly ?Grid $footers = null,
    ) {}

    public function plan(float $height, int $from, bool $continuing): Plan
    {
        $plan    = new Plan();
        $footers = $this->footerHeights();
        $height  -= array_sum($footers);
        $y       = $height < -self::EPSILON ? null : $this->placeHeaders($plan, $height, $from);
        if (null === $y) {
            $plan->result = FlowResult::BoxEmpty;

            return $plan;
        }
        $count = $this->body->count();
        $r     = $from;
        while ($r < $count) {
            $end     = $this->groupEnd($r);
            $heights = $this->layout->rowHeights($this->body, $r, $end);
            $needed  = array_sum($heights);
            if (($y + $needed) <= ($height + self::EPSILON)) {
                for ($k = $r; $k <= $end; ++$k) {
                    $this->add($plan, $k, $heights[$k] ?? 0.0, null);
                }
                $y += $needed;
                $r = $end + 1;
                continue;
            }
            if (null !== $plan->lastBodyRow) {
                break;
            }
            // Nothing placed yet: a group too tall for the box is placed row by row.
            $first = $heights[$r] ?? 0.0;
            if (($y + $first) <= ($height + self::EPSILON)) {
                $this->add($plan, $r, $first, null);
                $y += $first;
                ++$r;
                continue;
            }
            $this->cut($plan, $r, $height - $y, $continuing);
            break;
        }
        if (null !== $plan->lastBodyRow && null !== $this->footers) {
            $plan->footerHeights = $footers;
            foreach ($footers as $footer => $h) {
                $plan->rows[] = [$this->footers, $footer, $h, null];
            }
        }
        $plan->result = null === $plan->lastBodyRow ? FlowResult::BoxEmpty : FlowResult::BoxFull;
        if ([] !== $plan->rows && null !== $plan->lastBodyRow) {
            $plan->result = $r >= $count && null === $this->cutRow ? FlowResult::Done : FlowResult::BoxFull;
        }

        return $plan;
    }

    private ?int $cutRow = null;

    private function add(Plan $plan, int $row, float $height, ?float $limit): void
    {
        $plan->rows[]            = [$this->body, $row, $height, $limit];
        $plan->bodyHeights[$row] = $height;
        $plan->lastBodyRow       = $row;
    }

    /** Places a row that does not fit on its own: its text continues in the next box. */
    private function cut(Plan $plan, int $row, float $available, bool $continuing): void
    {
        if (! $this->options->splitRows || ! $this->body->hasTextLeft($row) || $available <= self::EPSILON) {
            return;
        }
        unset($continuing);
        $this->cutRow = $row;
        $this->add($plan, $row, $available, $available);
    }

    /** @return array<int, float> */
    private function footerHeights(): array
    {
        $count = $this->footers?->count() ?? 0;
        if (null === $this->footers || 0 === $count) {
            return [];
        }

        return $this->layout->rowHeights($this->footers, 0, $count - 1);
    }

    /**
     * The last row that has to be placed with $row: rows sharing its group id, and every row a
     * cell in those rows spans into, followed until nothing more is reached.
     */
    private function groupEnd(int $row): int
    {
        $last = $this->body->count() - 1;
        $end  = $row;
        for ($r = $row; $r <= $end; ++$r) {
            $group = $this->body->sources[$r]->group ?? null;
            while (null !== $group && $end < $last && ($this->body->sources[$end + 1]->group ?? null) === $group) {
                ++$end;
            }
            foreach ($this->body->rows[$r] ?? [] as $placed) {
                $end = max($end, min($last, $placed->lastRow()));
            }
        }

        return $end;
    }

    /** Adds the header rows; returns the height they take, or null when they do not fit. */
    private function placeHeaders(Plan $plan, float $height, int $from): ?float
    {
        $count = $this->headers->count();
        if (0 === $count || (! $this->options->repeatHeaders && $from > 0)) {
            return 0.0;
        }
        $heights = $this->layout->rowHeights($this->headers, 0, $count - 1);
        $total   = array_sum($heights);
        if ($total > ($height + self::EPSILON)) {
            return null;
        }
        $plan->headerHeights = $heights;
        foreach ($heights as $r => $h) {
            $plan->rows[] = [$this->headers, $r, $h, null];
        }

        return $total;
    }
}
