<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use function abs;
use function array_key_exists;
use function atan2;
use function count;
use function fmod;
use function hypot;
use function rad2deg;

/**
 * The vertices of a path where markers go, each with the angle markers orient to: the start of
 * each subpath and the end of each segment (an arc split into cubics is one segment). The angle
 * bisects the directions into and out of the vertex; a path's ends take the one direction they
 * have, unless the subpath is closed. A zero-length segment takes its direction from the
 * segments around it.
 *
 * @internal
 */
final class MarkerVertices
{
    private const float EPSILON = 1.0e-9;

    /**
     * @return list<array{0: float, 1: float, 2: float}> x, y and angle in degrees
     */
    public static function of(PathData $path): array
    {
        $vertices = [];
        foreach (self::subpaths($path) as [$x, $y, $implicit, $closed, $segments]) {
            $segments = self::fillDirections($segments);
            $count    = count($segments);
            $first    = $segments[0] ?? null;
            $last     = $segments[$count - 1] ?? null;
            if (! $implicit) {
                $vertices[] = [$x, $y, self::angle($closed ? $last[3] ?? null : null, $first[2] ?? null)];
            }
            foreach ($segments as $i => [$endX, $endY, , $in]) {
                $next       = $segments[$i + 1] ?? ($closed ? $first : null);
                $vertices[] = [$endX, $endY, self::angle($in, $next[2] ?? null)];
            }
        }

        return $vertices;
    }

    /**
     * The bisector of the directions in and out, in degrees from -180 to 180; either alone when
     * the other is missing. A reversal bisects as browsers do: the mean of the two angles.
     */
    private static function angle(?float $in, ?float $out): float
    {
        if (null === $in || null === $out) {
            return self::normalise(rad2deg($in ?? $out ?? 0.0));
        }
        $in  = rad2deg($in);
        $out = rad2deg($out);
        if (abs($in - $out) > 180.0) {
            $in += 360.0;
        }

        return self::normalise(($in + $out) / 2.0);
    }

    private static function direction(float $fromX, float $fromY, float $toX, float $toY): ?float
    {
        return hypot($toX - $fromX, $toY - $fromY) < self::EPSILON ? null : atan2($toY - $fromY, $toX - $fromX);
    }

    /**
     * Gives zero-length segments the direction of the segment before them, or after them at the
     * start of a subpath.
     *
     * @param list<array{0: float, 1: float, 2: ?float, 3: ?float}> $segments
     *
     * @return list<array{0: float, 1: float, 2: ?float, 3: ?float}>
     */
    private static function fillDirections(array $segments): array
    {
        $previous = null;
        foreach ($segments as $i => $segment) {
            if (null === $segment[2]) {
                $fill         = $previous ?? self::nextDirection($segments, $i);
                $segments[$i] = [$segment[0], $segment[1], $fill, $fill];
            }
            $previous = $segments[$i][3] ?? $previous;
        }

        return $segments;
    }

    /** @param list<array{0: float, 1: float, 2: ?float, 3: ?float}> $segments */
    private static function nextDirection(array $segments, int $from): ?float
    {
        for ($i = $from + 1; $i < count($segments); ++$i) {
            $direction = $segments[$i][2] ?? null;
            if (null !== $direction) {
                return $direction;
            }
        }

        return null;
    }

    private static function normalise(float $degrees): float
    {
        $degrees = fmod($degrees, num2: 360.0);
        if ($degrees > 180.0) {
            return $degrees - 360.0;
        }

        return $degrees <= -180.0 ? $degrees + 360.0 : $degrees;
    }

    /**
     * A segment's end point and its directions out of its start and into its end, from the
     * current point and the subpath's start (where closepath goes).
     *
     * @param list<float> $a
     *
     * @return array{0: float, 1: float, 2: ?float, 3: ?float}
     */
    private static function segment(string $op, array $a, float $x, float $y, float $startX, float $startY): array
    {
        if ('Z' === $op) {
            $direction = self::direction($x, $y, $startX, $startY);

            return [$startX, $startY, $direction, $direction];
        }
        if ('C' !== $op) {
            $endX      = $a[0] ?? 0.0;
            $endY      = $a[1] ?? 0.0;
            $direction = self::direction($x, $y, $endX, $endY);

            return [$endX, $endY, $direction, $direction];
        }
        [$x1, $y1, $x2, $y2, $endX, $endY] = [
            $a[0] ?? 0.0,
            $a[1] ?? 0.0,
            $a[2] ?? 0.0,
            $a[3] ?? 0.0,
            $a[4] ?? 0.0,
            $a[5] ?? 0.0,
        ];

        return [
            $endX,
            $endY,
            self::direction($x, $y, $x1, $y1)
            ?? self::direction($x, $y, $x2, $y2)
            ?? self::direction($x, $y, $endX, $endY),
            self::direction($x2, $y2, $endX, $endY)
            ?? self::direction($x1, $y1, $endX, $endY)
            ?? self::direction($x, $y, $endX, $endY),
        ];
    }

    /**
     * The path's subpaths: start point, whether it starts without a moveto (after a closepath),
     * whether it is closed, and its segments as end point with directions out of the start and
     * into the end (null for zero length). An arc's cubics make one segment.
     *
     * @return list<array{0: float, 1: float, 2: bool, 3: bool, 4: list<array{0: float, 1: float, 2: ?float, 3: ?float}>}>
     */
    private static function subpaths(PathData $path): array
    {
        $subpaths = [];
        $segments = [];
        $started  = false;
        $startX   = 0.0;
        $startY   = 0.0;
        $implicit = false;
        $closed   = false;
        $x        = 0.0;
        $y        = 0.0;
        $pending  = null;
        foreach ($path->commands as $index => [$op, $a]) {
            if ('M' === $op || ! $started || $closed) {
                if ($started) {
                    $subpaths[] = [$startX, $startY, $implicit, $closed, $segments];
                }
                $implicit = 'M' !== $op;
                $startX   = $implicit ? $x : $a[0] ?? 0.0;
                $startY   = $implicit ? $y : $a[1] ?? 0.0;
                [$x, $y] = [$startX, $startY];
                $started  = true;
                $segments = [];
            }
            if ('M' === $op) {
                $closed = false;
                continue;
            }
            $segment = self::segment($op, $a, $x, $y, $startX, $startY);
            $pending = null === $pending
                ? $segment
                : [$segment[0], $segment[1], $pending[2] ?? $segment[2], $segment[3] ?? $pending[3]];
            [$x, $y] = $segment;
            $closed = 'Z' === $op;
            if (array_key_exists($index, $path->inner)) {
                continue;
            }
            $segments[] = $pending;
            $pending    = null;
        }
        if ($started) {
            $subpaths[] = [$startX, $startY, $implicit, $closed, $segments];
        }

        return $subpaths;
    }
}
