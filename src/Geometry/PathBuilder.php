<?php

declare(strict_types=1);

namespace Php2Pdf\Geometry;

use function abs;
use function ceil;
use function cos;
use function count;
use function max;
use function sin;
use function tan;

use const M_PI;

/**
 * Builds a Path command by command, keeping the current point and the start of the open subpath.
 *
 * @phpstan-import-type Command from Path
 *
 * @api
 */
final class PathBuilder
{
    /** @var list<Command> */
    private array $commands = [];

    /** @var array<int, true> */
    private array $inner = [];

    private float $x      = 0.0;
    private float $y      = 0.0;
    private float $startX = 0.0;
    private float $startY = 0.0;

    /**
     * Cubics approximating an arc of the unit circle from $theta over $delta radians, mapped by
     * $ellipse, one per quarter turn or less, each marked as ending inside the arc rather than at
     * a vertex.
     */
    public function arcSegments(Matrix $ellipse, float $theta, float $delta): self
    {
        $segments = max(1, (int) ceil(abs($delta) / (M_PI / 2.0)));
        $step     = $delta / $segments;
        $k        = (4.0 / 3.0) * tan($step / 4.0);
        for ($i = 0; $i < $segments; ++$i) {
            $next = $theta + $step;
            $cos1 = cos($theta);
            $sin1 = sin($theta);
            $cos2 = cos($next);
            $sin2 = sin($next);
            [$x1, $y1] = $ellipse->apply($cos1 - ($k * $sin1), $sin1 + ($k * $cos1));
            [$x2, $y2] = $ellipse->apply($cos2 + ($k * $sin2), $sin2 - ($k * $cos2));
            [$x, $y] = $ellipse->apply($cos2, $sin2);
            $this->curveTo($x1, $y1, $x2, $y2, $x, $y)->continues();
            $theta = $next;
        }

        return $this;
    }

    public function build(): Path
    {
        return new Path($this->commands, $this->inner);
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

    /** A quadratic curve, raised to a cubic. */
    public function quadTo(float $cx, float $cy, float $x, float $y): self
    {
        $x1 = $this->x + ((2.0 / 3.0) * ($cx - $this->x));
        $y1 = $this->y + ((2.0 / 3.0) * ($cy - $this->y));
        $x2 = $x + ((2.0 / 3.0) * ($cx - $x));
        $y2 = $y + ((2.0 / 3.0) * ($cy - $y));

        return $this->curveTo($x1, $y1, $x2, $y2, $x, $y);
    }
}
