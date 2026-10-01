<?php

declare(strict_types=1);

namespace PhpPdf\Document;

use PhpPdf\Exception\InvalidArgumentException;
use PhpPdf\Geometry\Unit;

/**
 * A page size in points.
 *
 * @api
 */
final readonly class Dimensions
{
    public function __construct(
        public float $width,
        public float $height,
    ) {
        if ($width <= 0.0 || $height <= 0.0) {
            throw new InvalidArgumentException('Page dimensions must be positive.');
        }
    }

    public static function in(float $width, float $height): self
    {
        return new self(Unit::in($width), Unit::in($height));
    }

    public static function mm(float $width, float $height): self
    {
        return new self(Unit::mm($width), Unit::mm($height));
    }

    public static function pt(float $width, float $height): self
    {
        return new self($width, $height);
    }

    public function landscape(): self
    {
        return $this->width >= $this->height ? $this : new self($this->height, $this->width);
    }

    public function portrait(): self
    {
        return $this->height >= $this->width ? $this : new self($this->height, $this->width);
    }
}
