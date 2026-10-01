<?php

declare(strict_types=1);

namespace PhpPdf\Table;

use PhpPdf\Canvas\Canvas;
use PhpPdf\Canvas\PlaceableInterface;
use PhpPdf\Fit\Fit;
use PhpPdf\Fit\Position;
use PhpPdf\Geometry\Box;
use PhpPdf\Text\FlowResult;
use PhpPdf\Text\Style\Alignment;
use PhpPdf\Text\Style\VerticalAlignment;

use function max;

/**
 * Draws placed rows: backgrounds, then content, then borders, in canvas coordinates derived
 * from the table's box and the vertical offset of each row from the top.
 *
 * @internal
 */
final class Painter
{
    public function __construct(
        private readonly Canvas $canvas,
        private readonly Layout $layout,
        private readonly Box $box,
    ) {}

    /** The canvas box of an area $top points below the table's top edge. */
    public function area(float $x, float $top, float $width, float $height): Box
    {
        $y = $this->canvas->isTopDown() ? $this->box->y + $top : $this->box->far() - $top - $height;

        return new Box($this->box->x + $x, $y, $width, $height);
    }

    /** The canvas y of a horizontal edge $top points below the table's top. */
    public function edgeY(float $top): float
    {
        return $this->canvas->isTopDown() ? $this->box->y + $top : $this->box->far() - $top;
    }

    /**
     * Draws the cells starting in a row. A spanning cell is as tall as the placed rows it covers.
     *
     * @param list<PlacedCell>  $cells
     * @param array<int, float> $heights heights of the rows placed in this box
     *
     * @return bool whether any text of the row was placed
     */
    public function row(array $cells, float $top, array $heights, int $row, ?float $limit): bool
    {
        $placedText = false;
        foreach ($cells as $placed) {
            $height = 0.0;
            for ($r = $row; $r <= $placed->lastRow(); ++$r) {
                $height += $heights[$r] ?? 0.0;
            }
            if (null !== $limit) {
                $height = $limit;
            }
            $area = $this->area(
                $this->layout->edges[$placed->column] ?? 0.0,
                $top,
                $this->layout->spanWidth($placed),
                $height,
            );
            $this->background($placed, $area);
            if ($this->content($placed, $area)) {
                $placedText = true;
            }
            $this->borders($placed, $area);
        }

        return $placedText;
    }

    private function background(PlacedCell $placed, Box $area): void
    {
        $color = $placed->style->background;
        if (null === $color) {
            return;
        }
        $this->canvas
            ->save()
            ->setFillColor($color)
            ->box($area)
            ->fill()
            ->restore();
    }

    private function borders(PlacedCell $placed, Box $area): void
    {
        $topDown = $this->canvas->isTopDown();
        $yTop    = $topDown ? $area->y : $area->far();
        $yBottom = $topDown ? $area->far() : $area->y;
        $lines   = [
            'top'    => [$area->x, $yTop, $area->right(), $yTop],
            'bottom' => [$area->x, $yBottom, $area->right(), $yBottom],
            'left'   => [$area->x, $yTop, $area->x, $yBottom],
            'right'  => [$area->right(), $yTop, $area->right(), $yBottom],
        ];
        foreach ($lines as $side => [$x1, $y1, $x2, $y2]) {
            $border = $placed->style->side($side);
            if (null === $border || ! $border->isVisible()) {
                continue;
            }
            $this->canvas
                ->save()
                ->setStrokeColor($border->color)
                ->setLineWidth($border->width)
                ->setDash($border->dash)
                ->line($x1, $y1, $x2, $y2)
                ->stroke()
                ->restore();
        }
    }

    /** Draws the cell's content inside its padding; returns whether text was placed. */
    private function content(PlacedCell $placed, Box $area): bool
    {
        $padding = $placed->padding();
        $inner   = $area->inset($padding->left, $padding->top, $padding->right, $padding->bottom);
        if ($this->canvas->isTopDown()) {
            $inner = $area->inset($padding->left, $padding->bottom, $padding->right, $padding->top);
        }
        if ($inner->width <= 0.0 || $inner->height <= 0.0) {
            return false;
        }
        $vertical = $placed->style->verticalAlignment ?? VerticalAlignment::Top;
        if (null !== $placed->flow) {
            $before = $placed->flow->lastFit();
            $result = $this->canvas->fitTextflow($placed->flow, $inner, $this->layout->flowOptions($placed));

            return FlowResult::BoxEmpty !== $result || (null !== $before && $before->lineCount > 0);
        }
        $content = $placed->cell->content;
        if (! $content instanceof PlaceableInterface || $placed->drawn) {
            return false;
        }
        $placed->drawn = true;
        $this->canvas->place($content, $inner, $placed->cell->fit ?? Fit::meet($this->position($placed, $vertical)));

        return false;
    }

    private function position(PlacedCell $placed, VerticalAlignment $vertical): Position
    {
        $horizontal = match ($placed->style->alignment ?? Alignment::Left) {
            Alignment::Center => 0.5,
            Alignment::Right  => 1.0,
            default           => 0.0,
        };
        $v = match ($vertical) {
            VerticalAlignment::Top    => 1.0,
            VerticalAlignment::Center => 0.5,
            VerticalAlignment::Bottom => 0.0,
        };
        if ($this->canvas->isTopDown()) {
            $v = 1.0 - $v;
        }

        return Position::percent($horizontal, max(0.0, $v));
    }
}
