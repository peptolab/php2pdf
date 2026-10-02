<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Geometry\Matrix;
use Php2Pdf\Geometry\Path;
use Php2Pdf\Geometry\PathBuilder;

use function abs;
use function atan2;
use function cos;
use function count;
use function deg2rad;
use function fmod;
use function max;
use function preg_match_all;
use function sin;
use function sqrt;
use function strtoupper;

use const M_PI;

/**
 * Parses SVG path data (all commands, absolute and relative) into a Path of lines and
 * cubic curves. Quadratic curves are raised to cubics; arcs become cubic approximations.
 *
 * @internal
 */
final class PathParser
{
    private readonly PathBuilder $path;
    private ?float $lastControlX = null;
    private ?float $lastControlY = null;
    private string $lastCommand  = '';

    private function __construct()
    {
        $this->path = new PathBuilder();
    }

    public static function parse(?string $d): Path
    {
        $parser = new self();
        if (null === $d) {
            return $parser->path->build();
        }
        $m     = [];
        $found = preg_match_all('/([MmLlHhVvCcSsQqTtAaZz])|([+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?)/', $d, $m);
        if (false === $found || 0 === $found) {
            return $parser->path->build();
        }
        $command = '';
        $args    = [];
        $letters = $m[1] ?? [];
        foreach ($m[0] ?? [] as $i => $piece) {
            if ('' !== ($letters[$i] ?? '')) {
                $parser->run($command, $args);
                $command = $piece;
                $args    = [];
                continue;
            }
            $args[] = Length::toFloat($piece);
        }
        $parser->run($command, $args);

        return $parser->path->build();
    }

    /**
     * Applies one command. Arguments are offset by ($ox, $oy): the current point for relative
     * commands, zero for absolute ones.
     *
     * @param list<float> $a
     */
    private function apply(string $op, array $a, float $ox, float $oy): void
    {
        [$cx, $cy] = $this->path->point();
        $x = $ox + ($a[0] ?? 0.0);
        $y = $oy + ($a[1] ?? 0.0);
        match ($op) {
            'M'     => $this->path->moveTo($x, $y),
            'L'     => $this->path->lineTo($x, $y),
            'H'     => $this->path->lineTo($x, $cy),
            'V'     => $this->path->lineTo($cx, $oy + ($a[0] ?? 0.0)),
            'C' => $this->cubic(
                $x,
                $y,
                $ox + ($a[2] ?? 0.0),
                $oy + ($a[3] ?? 0.0),
                $ox + ($a[4] ?? 0.0),
                $oy + ($a[5] ?? 0.0),
            ),
            'S'     => $this->smoothCubic($x, $y, $ox + ($a[2] ?? 0.0), $oy + ($a[3] ?? 0.0)),
            'Q'     => $this->quad($x, $y, $ox + ($a[2] ?? 0.0), $oy + ($a[3] ?? 0.0)),
            'T'     => $this->smoothQuad($x, $y),
            'A' => $this->arc(
                [$a[0] ?? 0.0, $a[1] ?? 0.0, $a[2] ?? 0.0, $a[3] ?? 0.0, $a[4] ?? 0.0],
                $ox + ($a[5] ?? 0.0),
                $oy + ($a[6] ?? 0.0),
            ),
            default => null,
        };
        $this->lastCommand = $op;
    }

    /**
     * Elliptical arc per the SVG implementation notes: endpoint to centre parameterisation,
     * then one cubic per 90 degrees or less.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float} $a rx, ry, rotation, large-arc flag, sweep flag
     */
    private function arc(array $a, float $x, float $y): void
    {
        [$x0, $y0] = $this->path->point();
        $this->forgetControl();
        if (abs($x - $x0) < 1.0e-12 && abs($y - $y0) < 1.0e-12) {
            return;
        }
        $rx = abs($a[0]);
        $ry = abs($a[1]);
        if ($rx < 1.0e-12 || $ry < 1.0e-12) {
            $this->path->lineTo($x, $y);

            return;
        }
        $phi  = deg2rad($a[2]);
        $cosP = cos($phi);
        $sinP = sin($phi);
        $dx   = ($x0 - $x) / 2.0;
        $dy   = ($y0 - $y) / 2.0;
        $x1   = ($cosP * $dx) + ($sinP * $dy);
        $y1   = (-$sinP * $dx) + ($cosP * $dy);

        $lambda = (($x1 * $x1) / ($rx * $rx)) + (($y1 * $y1) / ($ry * $ry));
        if ($lambda > 1.0) {
            $rx *= sqrt($lambda);
            $ry *= sqrt($lambda);
        }
        $num   = ($rx * $rx * $ry * $ry) - ($rx * $rx * $y1 * $y1) - ($ry * $ry * $x1 * $x1);
        $den   = ($rx * $rx * $y1 * $y1) + ($ry * $ry * $x1 * $x1);
        $coef  = $den < 1.0e-12 ? 0.0 : sqrt(max(0.0, $num / $den));
        $large = 0.0 !== $a[3];
        $sweep = 0.0 !== $a[4];
        if ($large === $sweep) {
            $coef = -$coef;
        }
        $cxP = $coef * (($rx * $y1) / $ry);
        $cyP = $coef * -(($ry * $x1) / $rx);
        $cx  = ($cosP * $cxP) - ($sinP * $cyP) + (($x0 + $x) / 2.0);
        $cy  = ($sinP * $cxP) + ($cosP * $cyP) + (($y0 + $y) / 2.0);

        $theta1 = atan2(($y1 - $cyP) / $ry, ($x1 - $cxP) / $rx);
        $theta2 = atan2((-$y1 - $cyP) / $ry, (-$x1 - $cxP) / $rx);
        $delta  = $theta2 - $theta1;
        if ($sweep && $delta < 0.0) {
            $delta += 2.0 * M_PI;
        }
        if (! $sweep && $delta > 0.0) {
            $delta -= 2.0 * M_PI;
        }
        $ellipse = new Matrix($cosP * $rx, $sinP * $rx, -$sinP * $ry, $cosP * $ry, $cx, $cy);
        $this->path->arcSegments($ellipse, $theta1, fmod($delta, 2.0 * M_PI));
        $this->path->lineTo($x, $y);
    }

    private function cubic(float $x1, float $y1, float $x2, float $y2, float $x, float $y): void
    {
        $this->path->curveTo($x1, $y1, $x2, $y2, $x, $y);
        $this->lastControlX = $x2;
        $this->lastControlY = $y2;
    }

    private function forgetControl(): void
    {
        $this->lastControlX = null;
        $this->lastControlY = null;
    }

    private function quad(float $qx, float $qy, float $x, float $y): void
    {
        $this->path->quadTo($qx, $qy, $x, $y);
        $this->lastControlX = $qx;
        $this->lastControlY = $qy;
    }

    /** @param list<float> $args */
    private function run(string $command, array $args): void
    {
        if ('' === $command) {
            return;
        }
        $upper    = strtoupper($command);
        $relative = $command !== $upper;
        $arity    = match ($upper) {
            'M', 'L', 'T' => 2,
            'H', 'V'      => 1,
            'C'           => 6,
            'S', 'Q'      => 4,
            'A'           => 7,
            default       => 0,
        };
        if (0 === $arity) {
            $this->path->close();
            $this->forgetControl();
            $this->lastCommand = 'Z';

            return;
        }
        $count = (int) (count($args) / $arity);
        for ($i = 0; $i < $count; ++$i) {
            $slice = [];
            for ($k = 0; $k < $arity; ++$k) {
                $slice[] = $args[($i * $arity) + $k] ?? 0.0;
            }
            // Subsequent pairs after a moveto are implicit linetos.
            $op = 'M' === $upper && $i > 0 ? 'L' : $upper;
            [$cx, $cy] = $this->path->point();
            if ('M' === $op || 'L' === $op || 'H' === $op || 'V' === $op) {
                $this->forgetControl();
            }
            $this->apply($op, $slice, $relative ? $cx : 0.0, $relative ? $cy : 0.0);
        }
    }

    private function smoothCubic(float $x2, float $y2, float $x, float $y): void
    {
        [$cx, $cy] = $this->path->point();
        $reflect = 'C' === $this->lastCommand || 'S' === $this->lastCommand;
        $x1      = $reflect && null !== $this->lastControlX ? (2.0 * $cx) - $this->lastControlX : $cx;
        $y1      = $reflect && null !== $this->lastControlY ? (2.0 * $cy) - $this->lastControlY : $cy;
        $this->cubic($x1, $y1, $x2, $y2, $x, $y);
    }

    private function smoothQuad(float $x, float $y): void
    {
        [$cx, $cy] = $this->path->point();
        $reflect = 'Q' === $this->lastCommand || 'T' === $this->lastCommand;
        $qx      = $reflect && null !== $this->lastControlX ? (2.0 * $cx) - $this->lastControlX : $cx;
        $qy      = $reflect && null !== $this->lastControlY ? (2.0 * $cy) - $this->lastControlY : $cy;
        $this->quad($qx, $qy, $x, $y);
    }
}
