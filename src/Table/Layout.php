<?php

declare(strict_types=1);

namespace Php2Pdf\Table;

use Php2Pdf\Canvas\PlaceableInterface;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\LayoutException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Fit\FitMethod;
use Php2Pdf\Text\FlowOptions;
use Php2Pdf\Text\Style\VerticalAlignment;

use function array_fill;
use function array_key_exists;
use function array_sum;
use function count;
use function max;
use function sprintf;

/**
 * Table geometry: column widths for a table width (fixed, percentage and content-sized
 * columns) and row heights from the cells' content.
 *
 * @internal
 */
final class Layout
{
    /** @var list<float> */
    public readonly array $widths;

    /** @var list<float> x offsets of every column edge from the table's left, count columns + 1 */
    public readonly array $edges;

    /**
     * The minimum and maximum content widths of the auto columns, or null when there are none.
     * A table keeps the first ones, measured from its whole content, so its columns do not change
     * from box to box as text is placed.
     *
     * @var array{0: array<int, float>, 1: array<int, float>}|null
     */
    public readonly ?array $contentWidths;

    /**
     * @param list<Column>                                            $columns
     * @param list<Grid>                                              $grids         every grid whose content sizes auto columns
     * @param array{0: array<int, float>, 1: array<int, float>}|null $contentWidths widths measured before, to reuse
     *
     * @throws IoException
     * @throws ParseException
     */
    public function __construct(
        private readonly array $columns,
        array $grids,
        float $width,
        private readonly TableOptions $options,
        ?array $contentWidths = null,
    ) {
        $autos = [];
        foreach ($columns as $c => $column) {
            if (! $column->isAuto()) {
                continue;
            }
            $autos[] = $c;
        }
        $this->contentWidths = [] === $autos ? null : $contentWidths ?? $this->measureContentWidths($grids, $autos);
        $this->widths        = $this->resolveWidths($width);
        $edges               = [0.0];
        $x                   = 0.0;
        foreach ($this->widths as $w) {
            $x       += $w;
            $edges[] = $x;
        }
        $this->edges = $edges;
    }

    /** The height a cell's content needs at the cell's inner width, padding included. */
    public function contentHeight(PlacedCell $placed): float
    {
        $padding = $placed->padding();
        $inner   = $this->innerWidth($placed);
        $content = $placed->cell->content;
        $height  = match (true) {
            null !== $placed->flow => $placed->flow->measure($inner, $this->flowOptions($placed)),
            $content instanceof PlaceableInterface => $this->placeableHeight($content, $placed, $inner),
            default => 0.0,
        };

        return $height + $padding->vertical();
    }

    /**
     * How a cell's text is fitted: measuring and drawing share these so rows are drawn at the
     * height they were measured at.
     */
    public function flowOptions(PlacedCell $placed): FlowOptions
    {
        return new FlowOptions(
            firstBaseline: $placed->style->firstBaseline ?? $this->options->firstBaseline,
            verticalAlignment: $placed->style->verticalAlignment ?? VerticalAlignment::Top,
        );
    }

    public function innerWidth(PlacedCell $placed): float
    {
        return max(0.0, $this->spanWidth($placed) - $placed->padding()->horizontal());
    }

    /** The scale a placeable is drawn at inside a cell of the given inner width. */
    public function placeableScale(PlaceableInterface $content, PlacedCell $placed, float $innerWidth): float
    {
        $fit     = $placed->cell->fit;
        $natural = $content->naturalSize($fit?->dpi);
        $method  = null === $fit ? FitMethod::Meet : $fit->method;
        if (FitMethod::NoFit === $method || FitMethod::Clip === $method) {
            return null === $fit ? 1.0 : $fit->scaleX;
        }

        return $natural->width > 0.0 ? $innerWidth / $natural->width : 1.0;
    }

    /**
     * Heights of the rows $from..$to of a grid, from their cells' remaining content, the row
     * minimums and row spans.
     *
     * @return array<int, float> keyed by row index
     *
     * @throws IoException
     * @throws ParseException
     */
    public function rowHeights(Grid $grid, int $from, int $to): array
    {
        $heights = [];
        for ($r = $from; $r <= $to; ++$r) {
            $source = $grid->sources[$r] ?? null;
            $height = max($this->options->minRowHeight, null === $source ? 0.0 : $source->minHeight);
            foreach ($grid->cells($r) as $placed) {
                if ($placed->cell->rowspan > 1) {
                    continue;
                }
                $height = max($height, $this->contentHeight($placed));
            }
            $heights[$r] = $height;
        }
        // A spanning cell taller than the rows it covers grows the last of them.
        for ($r = $from; $r <= $to; ++$r) {
            foreach ($grid->cells($r) as $placed) {
                $last = $placed->lastRow();
                if ($placed->cell->rowspan <= 1 || $last > $to) {
                    continue;
                }
                $have = 0.0;
                for ($k = $r; $k <= $last; ++$k) {
                    $have += $heights[$k] ?? 0.0;
                }
                $need = $this->contentHeight($placed);
                if ($need > $have) {
                    $heights[$last] = ($heights[$last] ?? 0.0) + ($need - $have);
                }
            }
        }

        return $heights;
    }

    /** The width of the columns a cell spans. */
    public function spanWidth(PlacedCell $placed): float
    {
        $width = 0.0;
        for ($c = $placed->column; $c <= $placed->lastColumn(); ++$c) {
            $width += $this->widths[$c] ?? 0.0;
        }

        return $width;
    }

    /** @return array{0: float, 1: float} */
    private function cellContentWidths(PlacedCell $placed): array
    {
        if (null !== $placed->flow) {
            return [$placed->flow->minContentWidth(), $placed->flow->maxContentWidth()];
        }
        $content = $placed->cell->content;
        if ($content instanceof PlaceableInterface) {
            $fit   = $placed->cell->fit;
            $width = $content->naturalSize($fit?->dpi)->width * (null === $fit ? 1.0 : $fit->scaleX);

            return [0.0, $width];
        }

        return [0.0, 0.0];
    }

    /**
     * The minimum (widest word) and maximum (longest line) content widths of auto columns.
     *
     * @param list<Grid> $grids
     * @param list<int>  $autos
     *
     * @return array{0: array<int, float>, 1: array<int, float>}
     *
     * @throws IoException
     * @throws ParseException
     */
    private function measureContentWidths(array $grids, array $autos): array
    {
        $min = [];
        $max = [];
        foreach ($autos as $c) {
            $min[$c] = 0.0;
            $max[$c] = 0.0;
        }
        foreach ($grids as $grid) {
            for ($r = 0, $count = $grid->count(); $r < $count; ++$r) {
                foreach ($grid->cells($r) as $placed) {
                    $c = $placed->column;
                    if ($placed->cell->colspan > 1 || ! array_key_exists($c, $min)) {
                        continue;
                    }
                    $padding = $placed->padding()->horizontal();
                    [$lo, $hi] = $this->cellContentWidths($placed);
                    $min[$c] = max($min[$c] ?? 0.0, $lo + $padding);
                    $max[$c] = max($max[$c] ?? 0.0, $hi + $padding);
                }
            }
        }

        return [$min, $max];
    }

    private function placeableHeight(PlaceableInterface $content, PlacedCell $placed, float $innerWidth): float
    {
        $natural = $content->naturalSize($placed->cell->fit?->dpi);

        return $natural->height * $this->placeableScale($content, $placed, $innerWidth);
    }

    /** @return list<float> */
    private function resolveWidths(float $width): array
    {
        $count  = count($this->columns);
        $widths = array_fill(0, $count, value: 0.0);
        $fixed  = 0.0;
        $autos  = [];
        foreach ($this->columns as $c => $column) {
            if ($column->isAuto()) {
                $autos[] = $c;
                continue;
            }
            $widths[$c] = $column->points ?? (($width * ($column->percent ?? 0.0)) / 100.0);
            $fixed      += $widths[$c];
        }
        if ($fixed > ($width + 1.0e-6)) {
            throw new LayoutException(sprintf(
                'The columns need %.2f pt but the table is %.2f pt wide.',
                $fixed,
                $width,
            ));
        }
        if ([] === $autos || null === $this->contentWidths) {
            return $widths;
        }
        [$min, $max] = $this->contentWidths;
        $remaining = $width - $fixed;
        $sumMin    = array_sum($min);
        $sumMax    = array_sum($max);
        foreach ($autos as $c) {
            $widths[$c] = match (true) {
                // Room for every line unbroken: share the rest in proportion.
                $sumMax <= $remaining => ($max[$c] ?? 0.0)
                    + (($remaining - $sumMax) * ($sumMax > 0.0 ? ($max[$c] ?? 0.0) / $sumMax : 1.0 / count($autos))),
                // Between the widest words and the longest lines: grow from the minimum.
                $sumMin <= $remaining => ($min[$c] ?? 0.0)
                    + (
                        ($remaining - $sumMin)
                        * (
                            ($sumMax - $sumMin) > 0.0
                                ? (($max[$c] ?? 0.0) - ($min[$c] ?? 0.0)) / ($sumMax - $sumMin)
                                : 1.0 / count($autos)
                        )
                    ),
                // Not even the widest words fit: shrink them proportionally; words will break.
                default => ($min[$c] ?? 0.0) * ($sumMin > 0.0 ? $remaining / $sumMin : 1.0 / count($autos)),
            };
        }

        return $widths;
    }
}
