<?php

declare(strict_types=1);

namespace Php2Pdf\Geometry;

use Php2Pdf\Exception\InvalidArgumentException;

use function abs;
use function max;
use function min;

/**
 * An axis-aligned rectangle in points, given by its origin corner and size.
 *
 * Boxes are origin-agnostic: on a bottom-left page y is the bottom edge, on a top-down page it is
 * the top edge. Page geometry (media, trim boxes) and placement boxes both use this type.
 *
 * @api
 */
final readonly class Box
{
    public function __construct(
        public float $x,
        public float $y,
        public float $width,
        public float $height,
    ) {
        if ($width < 0.0 || $height < 0.0) {
            throw new InvalidArgumentException('Box width and height must not be negative.');
        }
    }

    public static function fromCorners(float $x1, float $y1, float $x2, float $y2): self
    {
        return new self(min($x1, $x2), min($y1, $y2), abs($x2 - $x1), abs($y2 - $y1));
    }

    public static function fromIn(float $x, float $y, float $width, float $height): self
    {
        return new self(Unit::in($x), Unit::in($y), Unit::in($width), Unit::in($height));
    }

    public static function fromMm(float $x, float $y, float $width, float $height): self
    {
        return new self(Unit::mm($x), Unit::mm($y), Unit::mm($width), Unit::mm($height));
    }

    public static function fromPoints(float $x, float $y, float $width, float $height): self
    {
        return new self($x, $y, $width, $height);
    }

    public function area(): float
    {
        return $this->width * $this->height;
    }

    public function centerX(): float
    {
        return $this->x + ($this->width / 2.0);
    }

    public function centerY(): float
    {
        return $this->y + ($this->height / 2.0);
    }

    public function contains(float $x, float $y): bool
    {
        return $x >= $this->x && $x <= $this->right() && $y >= $this->y && $y <= $this->far();
    }

    /** Whether another box lies within this one, edges included, to within $tolerance points. */
    public function encloses(self $other, float $tolerance = 0.0): bool
    {
        return (
            $other->x >= ($this->x - $tolerance)
                && $other->y >= ($this->y - $tolerance)
                && $other->right() <= ($this->right() + $tolerance)
                && $other->far() <= ($this->far() + $tolerance)
        );
    }

    /** The far y edge (y + height). */
    public function far(): float
    {
        return $this->y + $this->height;
    }

    /**
     * Shrinks the box by the given amounts. Pass one value for all sides, or left/far/right/near.
     */
    public function inset(float $left, ?float $far = null, ?float $right = null, ?float $near = null): self
    {
        $far   ??= $left;
        $right ??= $left;
        $near  ??= $far;

        return new self(
            $this->x + $left,
            $this->y + $near,
            max(0.0, $this->width - $left - $right),
            max(0.0, $this->height - $near - $far),
        );
    }

    public function intersect(self $other): self
    {
        $x1 = max($this->x, $other->x);
        $y1 = max($this->y, $other->y);
        $x2 = min($this->right(), $other->right());
        $y2 = min($this->far(), $other->far());
        if ($x2 <= $x1 || $y2 <= $y1) {
            return new self($x1, $y1, 0.0, 0.0);
        }

        return new self($x1, $y1, $x2 - $x1, $y2 - $y1);
    }

    public function isEmpty(): bool
    {
        return 0.0 === $this->width || 0.0 === $this->height;
    }

    /** The far x edge (x + width). */
    public function right(): float
    {
        return $this->x + $this->width;
    }

    /**
     * The PDF rectangle form [x1 y1 x2 y2].
     *
     * @return list<float>
     */
    public function toRect(): array
    {
        return [$this->x, $this->y, $this->right(), $this->far()];
    }

    public function translate(float $dx, float $dy): self
    {
        return new self($this->x + $dx, $this->y + $dy, $this->width, $this->height);
    }

    public function union(self $other): self
    {
        return self::fromCorners(
            min($this->x, $other->x),
            min($this->y, $other->y),
            max($this->right(), $other->right()),
            max($this->far(), $other->far()),
        );
    }

    public function withSize(float $width, float $height): self
    {
        return new self($this->x, $this->y, $width, $height);
    }
}
