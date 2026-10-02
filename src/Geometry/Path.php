<?php

declare(strict_types=1);

namespace Php2Pdf\Geometry;

use function abs;
use function cos;
use function count;
use function deg2rad;
use function min;
use function sin;
use function sqrt;

/**
 * A path of absolute move, line, cubic curve and close commands: built once, by a PathBuilder
 * or one of the shapes here, then shared. It knows its exact bounds and can be transformed;
 * a canvas draws it.
 *
 * @phpstan-type Command array{0: 'M'|'L'|'C'|'Z', 1: list<float>}
 *
 * @api
 */
final readonly class Path
{
    /** The Bézier control-point factor for a quarter circle. */
    public const float KAPPA = 0.552_284_749_830_793_6;

    /**
     * @param list<Command>   $commands 'M' [x y], 'L' [x y], 'C' [x1 y1 x2 y2 x y], 'Z' []
     * @param array<int, true> $inner    commands that end inside a source segment (an arc's
     *                                   cubics), so not at a vertex
     */
    public function __construct(
        public array $commands = [],
        public array $inner = [],
    ) {}

    /**
     * A circular arc from $startDegrees to $endDegrees, counter-clockwise for a positive sweep
     * in y-up space, one cubic per quarter circle or less. A sweep beyond a full turn is one turn.
     */
    public static function arc(float $cx, float $cy, float $radius, float $startDegrees, float $endDegrees): self
    {
        $sweep = $endDegrees - $startDegrees;
        if (abs($sweep) > 360.0) {
            $sweep = $sweep < 0.0 ? -360.0 : 360.0;
        }
        $circle = new Matrix($radius, 0.0, 0.0, $radius, $cx, $cy);
        $start  = deg2rad($startDegrees);

        return (new PathBuilder())->moveTo(...$circle->apply(cos($start), sin($start)))
            ->arcSegments($circle, $start, deg2rad($sweep))
            ->build();
    }

    public static function ellipse(float $cx, float $cy, float $rx, float $ry): self
    {
        $kx = $rx * self::KAPPA;
        $ky = $ry * self::KAPPA;

        return (new PathBuilder())->moveTo($cx + $rx, $cy)
            ->curveTo($cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry)
            ->curveTo($cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy)
            ->curveTo($cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry)
            ->curveTo($cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy)
            ->close()
            ->build();
    }

    /** @param list<float> $points x y pairs */
    public static function polygon(array $points): self
    {
        $path = self::polyline($points);

        return $path->isEmpty() ? $path : new self([...$path->commands, ['Z', []]]);
    }

    /** @param list<float> $points x y pairs */
    public static function polyline(array $points): self
    {
        $builder = new PathBuilder();
        $count   = (int) (count($points) / 2);
        for ($i = 0; $i < $count; ++$i) {
            $x = $points[2 * $i] ?? 0.0;
            $y = $points[(2 * $i) + 1] ?? 0.0;
            if (0 === $i) {
                $builder->moveTo($x, $y);
                continue;
            }
            $builder->lineTo($x, $y);
        }

        return $builder->build();
    }

    /** A rectangle, with elliptical corners of radii $rx and $ry when either is positive. */
    public static function rect(float $x, float $y, float $w, float $h, float $rx = 0.0, float $ry = 0.0): self
    {
        if ($rx <= 0.0 && $ry <= 0.0) {
            return (new PathBuilder())->moveTo($x, $y)
                ->lineTo($x + $w, $y)
                ->lineTo($x + $w, $y + $h)
                ->lineTo($x, $y + $h)
                ->close()
                ->build();
        }

        return self::roundedRect($x, $y, $w, $h, min($rx, $w / 2.0), min($ry, $h / 2.0));
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

    private static function roundedRect(float $x, float $y, float $w, float $h, float $rx, float $ry): self
    {
        $kx = $rx * self::KAPPA;
        $ky = $ry * self::KAPPA;

        return (new PathBuilder())->moveTo($x + $rx, $y)
            ->lineTo($x + $w - $rx, $y)
            ->curveTo($x + $w - $rx + $kx, $y, $x + $w, $y + $ry - $ky, $x + $w, $y + $ry)
            ->lineTo($x + $w, $y + $h - $ry)
            ->curveTo($x + $w, $y + $h - $ry + $ky, $x + $w - $rx + $kx, $y + $h, $x + $w - $rx, $y + $h)
            ->lineTo($x + $rx, $y + $h)
            ->curveTo($x + $rx - $kx, $y + $h, $x, $y + $h - $ry + $ky, $x, $y + $h - $ry)
            ->lineTo($x, $y + $ry)
            ->curveTo($x, $y + $ry - $ky, $x + $rx - $kx, $y, $x + $rx, $y)
            ->close()
            ->build();
    }

    /** This path followed by another, as one path. */
    public function append(self $other): self
    {
        if ($other->isEmpty()) {
            return $this;
        }
        $inner = $this->inner;
        $base  = count($this->commands);
        foreach ($other->inner as $index => $true) {
            $inner[$base + $index] = $true;
        }

        return new self([...$this->commands, ...$other->commands], $inner);
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

    public function isEmpty(): bool
    {
        return [] === $this->commands;
    }

    /** The path continuing one already open: its first move becomes a line. */
    public function joined(): self
    {
        $first = $this->commands[0] ?? null;
        if (null === $first || 'M' !== $first[0]) {
            return $this;
        }
        $commands    = $this->commands;
        $commands[0] = ['L', $first[1]];

        return new self($commands, $this->inner);
    }

    /** The same path with every point transformed. */
    public function transformed(Matrix $matrix): self
    {
        if ($matrix->isIdentity()) {
            return $this;
        }
        $commands = [];
        foreach ($this->commands as [$op, $a]) {
            $points = [];
            for ($i = 0; ($i + 1) < count($a); $i += 2) {
                [$x, $y] = $matrix->apply($a[$i] ?? 0.0, $a[$i + 1] ?? 0.0);
                $points[] = $x;
                $points[] = $y;
            }
            $commands[] = [$op, $points];
        }

        return new self($commands, $this->inner);
    }
}
