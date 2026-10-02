<?php

declare(strict_types=1);

namespace Php2Pdf\Geometry;

use function max;
use function min;

/**
 * Grows an axis-aligned box point by point.
 *
 * @internal
 */
final class BoundsAccumulator
{
    private ?float $minX = null;
    private ?float $minY = null;
    private ?float $maxX = null;
    private ?float $maxY = null;

    public function add(float $x, float $y): void
    {
        $this->minX = null === $this->minX ? $x : min($this->minX, $x);
        $this->minY = null === $this->minY ? $y : min($this->minY, $y);
        $this->maxX = null === $this->maxX ? $x : max($this->maxX, $x);
        $this->maxY = null === $this->maxY ? $y : max($this->maxY, $y);
    }

    public function box(): ?Box
    {
        if (null === $this->minX || null === $this->minY || null === $this->maxX || null === $this->maxY) {
            return null;
        }

        return Box::fromCorners($this->minX, $this->minY, $this->maxX, $this->maxY);
    }
}
