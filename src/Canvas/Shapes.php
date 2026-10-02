<?php

declare(strict_types=1);

namespace Php2Pdf\Canvas;

use Php2Pdf\Core\Num;

use function abs;
use function ceil;
use function cos;
use function deg2rad;
use function max;
use function min;
use function sin;
use function tan;

/**
 * Curved shapes as Bézier paths: circular arcs, ellipses and rounded rectangles.
 *
 * @internal
 */
final class Shapes
{
    /** Bézier control-point factor for a quarter circle. */
    private const float KAPPA = 0.552_284_749_830_793_6;

    /**
     * A circular arc from $startDegrees to $endDegrees, in quarter-circle segments at most. It
     * joins the open path with a line, or starts a new one.
     */
    public static function arc(
        ContentStream $stream,
        float $cx,
        float $cy,
        float $radius,
        float $startDegrees,
        float $endDegrees,
    ): void {
        $sweep = $endDegrees - $startDegrees;
        if (abs($sweep) > 360.0) {
            $sweep = $sweep < 0.0 ? -360.0 : 360.0;
        }
        $segments = max(1, (int) ceil(abs($sweep) / 90.0));
        $step     = deg2rad($sweep / $segments);
        $angle    = deg2rad($startDegrees);
        $x        = $cx + ($radius * cos($angle));
        $y        = $cy + ($radius * sin($angle));
        $stream->path(Num::list([$x, $y]) . ($stream->hasCurrentPoint() ? ' l' : ' m'));

        $k = (4.0 / 3.0) * tan($step / 4.0);
        for ($i = 0; $i < $segments; ++$i) {
            $next = $angle + $step;
            $cos1 = cos($angle);
            $sin1 = sin($angle);
            $cos2 = cos($next);
            $sin2 = sin($next);
            $stream->curveTo(
                $cx + ($radius * ($cos1 - ($k * $sin1))),
                $cy + ($radius * ($sin1 + ($k * $cos1))),
                $cx + ($radius * ($cos2 + ($k * $sin2))),
                $cy + ($radius * ($sin2 - ($k * $cos2))),
                $cx + ($radius * $cos2),
                $cy + ($radius * $sin2),
            );
            $angle = $next;
        }
    }

    public static function ellipse(ContentStream $stream, float $cx, float $cy, float $rx, float $ry): void
    {
        $kx = self::KAPPA * $rx;
        $ky = self::KAPPA * $ry;
        $stream->moveTo($cx + $rx, $cy);
        $stream->curveTo($cx + $rx, $cy + $ky, $cx + $kx, $cy + $ry, $cx, $cy + $ry);
        $stream->curveTo($cx - $kx, $cy + $ry, $cx - $rx, $cy + $ky, $cx - $rx, $cy);
        $stream->curveTo($cx - $rx, $cy - $ky, $cx - $kx, $cy - $ry, $cx, $cy - $ry);
        $stream->curveTo($cx + $kx, $cy - $ry, $cx + $rx, $cy - $ky, $cx + $rx, $cy);
        $stream->closePath();
    }

    /** A rectangle with quarter-circle corners; a plain rectangle when the radius is not positive. */
    public static function roundedRect(
        ContentStream $stream,
        float $x,
        float $y,
        float $width,
        float $height,
        float $radius,
    ): void {
        $r = min($radius, $width / 2.0, $height / 2.0);
        if ($r <= 0.0) {
            $stream->rect($x, $y, $width, $height);

            return;
        }
        $k  = self::KAPPA * $r;
        $x2 = $x + $width;
        $y2 = $y + $height;
        $stream->moveTo($x + $r, $y);
        $stream->lineTo($x2 - $r, $y);
        $stream->curveTo($x2 - $r + $k, $y, $x2, $y + $r - $k, $x2, $y + $r);
        $stream->lineTo($x2, $y2 - $r);
        $stream->curveTo($x2, $y2 - $r + $k, $x2 - $r + $k, $y2, $x2 - $r, $y2);
        $stream->lineTo($x + $r, $y2);
        $stream->curveTo($x + $r - $k, $y2, $x, $y2 - $r + $k, $x, $y2 - $r);
        $stream->lineTo($x, $y + $r);
        $stream->curveTo($x, $y + $r - $k, $x + $r - $k, $y, $x + $r, $y);
        $stream->closePath();
    }
}
