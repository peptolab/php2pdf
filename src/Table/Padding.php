<?php

declare(strict_types=1);

namespace Php2Pdf\Table;

use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Geometry\Unit;

/**
 * Space between a cell's edges and its content, in points.
 *
 * @api
 */
final readonly class Padding
{
    public function __construct(
        public float $top = 0.0,
        public float $right = 0.0,
        public float $bottom = 0.0,
        public float $left = 0.0,
    ) {
        if ($top < 0.0 || $right < 0.0 || $bottom < 0.0 || $left < 0.0) {
            throw new InvalidArgumentException('Padding cannot be negative.');
        }
    }

    public static function all(float $points): self
    {
        return new self($points, $points, $points, $points);
    }

    public static function mm(float $top, float $right, float $bottom, float $left): self
    {
        return new self(Unit::mm($top), Unit::mm($right), Unit::mm($bottom), Unit::mm($left));
    }

    public static function none(): self
    {
        return new self();
    }

    /** The same padding top and bottom, and left and right. */
    public static function symmetric(float $vertical, float $horizontal): self
    {
        return new self($vertical, $horizontal, $vertical, $horizontal);
    }

    public function horizontal(): float
    {
        return $this->left + $this->right;
    }

    public function vertical(): float
    {
        return $this->top + $this->bottom;
    }
}
