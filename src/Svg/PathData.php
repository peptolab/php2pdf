<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Matrix;

use function abs;
use function count;
use function min;
use function sqrt;

/**
 * A path reduced to absolute move, line, cubic curve and close commands, with its exact
 * bounding box and transformation.
 *
 * @internal
 */
final class PathData
{
    /** @var list<array{0: string, 1: list<float>}> 'M' [x y], 'L' [x y], 'C' [x1 y1 x2 y2 x y], 'Z' [] */
    public array $commands = [];

    /** @var array<int, true> commands that end inside a source segment (an arc's cubics), so not at a vertex */
    public array $inner = [];

    private float $x      = 0.0;
    private float $y      = 0.0;
    private float $startX = 0.0;
    private float $startY = 0.0;

    public static function ellipse(float $cx, float $cy, float $rx, float $ry): self
    {
        $kx = $rx * 0.552_284_749_830_793_6;
        $ky = $ry * 0.552_284_749_830_793_6;

        $path = new self();

        return $path->moveTo($cx + $rx, $cy)
            ->curveTo($cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry)
            ->curveTo($cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy)
            ->curveTo($cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry)
            ->curveTo($cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy)
            ->close();
    }

    /** @param list<float> $points x y pairs */
    public static function polygon(array $points): self
    {
        $path = self::polyline($points);
        if (! $path->isEmpty()) {
            $path->close();
        }

        return $path;
    }

    /** @param list<float> $points x y pairs */
    public static function polyline(array $points): self
    {
        $path  = new self();
        $count = (int) (count($points) / 2);
        for ($i = 0; $i < $count; ++$i) {
            $x = $points[2 * $i] ?? 0.0;
            $y = $points[(2 * $i) + 1] ?? 0.0;
            if (0 === $i) {
                $path->moveTo($x, $y);
                continue;
            }
            $path->lineTo($x, $y);
        }

        return $path;
    }

    public static function rect(float $x, float $y, float $w, float $h, float $rx = 0.0, float $ry = 0.0): self
    {
        $path = new self();
        if ($rx <= 0.0 && $ry <= 0.0) {
            return $path->moveTo($x, $y)
                ->lineTo($x + $w, $y)
                ->lineTo($x + $w, $y + $h)
                ->lineTo($x, $y + $h)
                ->close();
        }
        $rx = min($rx, $w / 2.0);
        $ry = min($ry, $h / 2.0);
        $kx = $rx * 0.552_284_749_830_793_6;
        $ky = $ry * 0.552_284_749_830_793_6;

        return $path->moveTo($x + $rx, $y)
            ->lineTo($x + $w - $rx, $y)
            ->curveTo($x + $w - $rx + $kx, $y, $x + $w, $y + $ry - $ky, $x + $w, $y + $ry)
            ->lineTo($x + $w, $y + $h - $ry)
            ->curveTo($x + $w, $y + $h - $ry + $ky, $x + $w - $rx + $kx, $y + $h, $x + $w - $rx, $y + $h)
            ->lineTo($x + $rx, $y + $h)
            ->curveTo($x + $rx - $kx, $y + $h, $x, $y + $h - $ry + $ky, $x, $y + $h - $ry)
            ->lineTo($x, $y + $ry)
            ->curveTo($x, $y + $ry - $ky, $x + $rx - $kx, $y, $x + $rx, $y)
            ->close();
    }

    private static function cubicAt(float $p0, float $p1, float $p2, float $p3, float $t): float
    {
        $u = 1.0 - $t;

        return ($u * $u * $u * $p0) + (3.0 * $u * $u * $t * $p1) + (3.0 * $u * $t * $t * $p2) + ($t * $t * $t * $p3);
    }

    /**
     * Parameters in (0, 1) where one coordinate of a cubic reaches an extremum.
     *
     * @return list<float>
     */
    private static function cubicExtrema(float $p0, float $p1, float $p2, float $p3): array
    {
        $a     = (-$p0 + (3.0 * $p1) - (3.0 * $p2) + $p3) * 3.0;
        $b     = ($p0 - (2.0 * $p1) + $p2) * 6.0;
        $c     = ($p1 - $p0) * 3.0;
        $roots = [];
        if (abs($a) < 1.0e-12) {
            if (abs($b) > 1.0e-12) {
                $roots[] = -$c / $b;
            }
        }
        if (abs($a) >= 1.0e-12) {
            $disc = ($b * $b) - (4.0 * $a * $c);
            if ($disc >= 0.0) {
                $sq      = sqrt($disc);
                $roots[] = (-$b + $sq) / (2.0 * $a);
                $roots[] = (-$b - $sq) / (2.0 * $a);
            }
        }
        $out = [];
        foreach ($roots as $t) {
            if ($t <= 0.0 || $t >= 1.0) {
                continue;
            }
            $out[] = $t;
        }

        return $out;
    }

    /** @param list<float> $a control points and end point of a cubic from ($px, $py) */
    private static function curveBounds(BoundsAccumulator $box, float $px, float $py, array $a): void
    {
        $xs = [$px, $a[0] ?? 0.0, $a[2] ?? 0.0, $a[4] ?? 0.0];
        $ys = [$py, $a[1] ?? 0.0, $a[3] ?? 0.0, $a[5] ?? 0.0];
        foreach ([...self::cubicExtrema(...$xs), ...self::cubicExtrema(...$ys)] as $t) {
            $box->add(self::cubicAt(...[...$xs, $t]), self::cubicAt(...[...$ys, $t]));
        }
    }

    /** The exact axis-aligned bounds, or null for an empty path. */
    public function bounds(): ?Box
    {
        $box = new BoundsAccumulator();
        $px  = 0.0;
        $py  = 0.0;
        foreach ($this->commands as [$op, $a]) {
            if ('Z' === $op) {
                continue;
            }
            if ('C' === $op) {
                self::curveBounds($box, $px, $py, $a);
            }
            $px = 'C' === $op ? $a[4] ?? 0.0 : $a[0] ?? 0.0;
            $py = 'C' === $op ? $a[5] ?? 0.0 : $a[1] ?? 0.0;
            $box->add($px, $py);
        }

        return $box->box();
    }

    public function close(): self
    {
        $this->commands[] = ['Z', []];
        $this->x          = $this->startX;
        $this->y          = $this->startY;

        return $this;
    }

    /** Marks the last command as ending inside a source segment, not at a vertex. */
    public function continues(): self
    {
        $this->inner[count($this->commands) - 1] = true;

        return $this;
    }

    public function curveTo(float $x1, float $y1, float $x2, float $y2, float $x, float $y): self
    {
        $this->commands[] = ['C', [$x1, $y1, $x2, $y2, $x, $y]];
        $this->x          = $x;
        $this->y          = $y;

        return $this;
    }

    /** Appends the path to the canvas's current path. */
    public function emit(Canvas $canvas): void
    {
        foreach ($this->commands as [$op, $a]) {
            match ($op) {
                'M'     => $canvas->moveTo($a[0] ?? 0.0, $a[1] ?? 0.0),
                'L'     => $canvas->lineTo($a[0] ?? 0.0, $a[1] ?? 0.0),
                'C' => $canvas->curveTo(
                    $a[0] ?? 0.0,
                    $a[1] ?? 0.0,
                    $a[2] ?? 0.0,
                    $a[3] ?? 0.0,
                    $a[4] ?? 0.0,
                    $a[5] ?? 0.0,
                ),
                default => $canvas->closePath(),
            };
        }
    }

    public function isEmpty(): bool
    {
        return [] === $this->commands;
    }

    public function lineTo(float $x, float $y): self
    {
        $this->commands[] = ['L', [$x, $y]];
        $this->x          = $x;
        $this->y          = $y;

        return $this;
    }

    public function moveTo(float $x, float $y): self
    {
        $this->commands[] = ['M', [$x, $y]];
        $this->x          = $x;
        $this->y          = $y;
        $this->startX     = $x;
        $this->startY     = $y;

        return $this;
    }

    /**
     * The current point.
     *
     * @return array{0: float, 1: float}
     */
    public function point(): array
    {
        return [$this->x, $this->y];
    }

    /** Quadratic curve, raised to a cubic. */
    public function quadTo(float $cx, float $cy, float $x, float $y): self
    {
        $x1 = $this->x + ((2.0 / 3.0) * ($cx - $this->x));
        $y1 = $this->y + ((2.0 / 3.0) * ($cy - $this->y));
        $x2 = $x + ((2.0 / 3.0) * ($cx - $x));
        $y2 = $y + ((2.0 / 3.0) * ($cy - $y));

        return $this->curveTo($x1, $y1, $x2, $y2, $x, $y);
    }

    /** The same path with every point transformed. */
    public function transformed(Matrix $matrix): self
    {
        if ($matrix->isIdentity()) {
            return $this;
        }
        $out = new self();
        foreach ($this->commands as [$op, $a]) {
            $points = [];
            for ($i = 0; ($i + 1) < count($a); $i += 2) {
                [$x, $y] = $matrix->apply($a[$i] ?? 0.0, $a[$i + 1] ?? 0.0);
                $points[] = $x;
                $points[] = $y;
            }
            $out->commands[] = [$op, $points];
        }
        $out->inner = $this->inner;

        return $out;
    }
}
