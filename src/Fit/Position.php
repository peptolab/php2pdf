<?php

declare(strict_types=1);

namespace PhpPdf\Fit;

use PhpPdf\Exception\InvalidArgumentException;

/**
 * Where an object sits inside its box, as fractions: 0 is left or bottom, 1 is right or top.
 * "Top" and "bottom" are visual, whatever the page's coordinate origin.
 *
 * @api
 */
final readonly class Position
{
    public function __construct(
        public float $horizontal,
        public float $vertical,
    ) {
        if ($horizontal < 0.0 || $horizontal > 1.0 || $vertical < 0.0 || $vertical > 1.0) {
            throw new InvalidArgumentException('Position fractions must be between 0 and 1.');
        }
    }

    public static function bottomCenter(): self
    {
        return new self(0.5, 0.0);
    }

    public static function bottomLeft(): self
    {
        return new self(0.0, 0.0);
    }

    public static function bottomRight(): self
    {
        return new self(1.0, 0.0);
    }

    public static function center(): self
    {
        return new self(0.5, 0.5);
    }

    public static function centerLeft(): self
    {
        return new self(0.0, 0.5);
    }

    public static function centerRight(): self
    {
        return new self(1.0, 0.5);
    }

    /** From percentages, 0 to 100. */
    public static function percent(float $horizontal, float $vertical): self
    {
        return new self($horizontal / 100.0, $vertical / 100.0);
    }

    public static function topCenter(): self
    {
        return new self(0.5, 1.0);
    }

    public static function topLeft(): self
    {
        return new self(0.0, 1.0);
    }

    public static function topRight(): self
    {
        return new self(1.0, 1.0);
    }
}
