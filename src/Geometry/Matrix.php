<?php

declare(strict_types=1);

namespace PhpPdf\Geometry;

use PhpPdf\Core\Num;
use PhpPdf\Exception\InvalidArgumentException;

use function abs;
use function cos;
use function deg2rad;
use function max;
use function min;
use function sin;
use function tan;

/**
 * A 2D affine transform in PDF form [a b c d e f]:
 *   x' = a·x + c·y + e
 *   y' = b·x + d·y + f
 *
 * Composition follows PDF semantics: $m->then($n) applies $m first, then $n.
 *
 * @api
 */
final readonly class Matrix
{
    public function __construct(
        public float $a = 1.0,
        public float $b = 0.0,
        public float $c = 0.0,
        public float $d = 1.0,
        public float $e = 0.0,
        public float $f = 0.0,
    ) {}

    public static function identity(): self
    {
        return new self();
    }

    /** Counter-clockwise rotation in degrees. */
    public static function rotation(float $degrees): self
    {
        $r   = deg2rad($degrees);
        $cos = cos($r);
        $sin = sin($r);

        return new self(self::snap($cos), self::snap($sin), self::snap(-$sin), self::snap($cos), 0.0, 0.0);
    }

    public static function scaling(float $sx, ?float $sy = null): self
    {
        return new self($sx, 0.0, 0.0, $sy ?? $sx, 0.0, 0.0);
    }

    /** Skew by angles in degrees along x and y. */
    public static function skewing(float $alphaDegrees, float $betaDegrees): self
    {
        return new self(1.0, tan(deg2rad($alphaDegrees)), tan(deg2rad($betaDegrees)), 1.0, 0.0, 0.0);
    }

    public static function translation(float $tx, float $ty): self
    {
        return new self(1.0, 0.0, 0.0, 1.0, $tx, $ty);
    }

    private static function snap(float $v): float
    {
        return abs($v) < 1.0e-12 ? 0.0 : $v;
    }

    /** Applies $first, then this matrix. Equivalent to $first->then($this). */
    public function after(self $first): self
    {
        return $first->then($this);
    }

    /** @return array{0: float, 1: float} */
    public function apply(float $x, float $y): array
    {
        return [($this->a * $x) + ($this->c * $y) + $this->e, ($this->b * $x) + ($this->d * $y) + $this->f];
    }

    /** The axis-aligned bounds of a box after transformation. */
    public function applyToBox(Box $box): Box
    {
        [$x1, $y1] = $this->apply($box->x, $box->y);
        [$x2, $y2] = $this->apply($box->right(), $box->y);
        [$x3, $y3] = $this->apply($box->right(), $box->far());
        [$x4, $y4] = $this->apply($box->x, $box->far());

        return Box::fromCorners(
            min($x1, $x2, $x3, $x4),
            min($y1, $y2, $y3, $y4),
            max($x1, $x2, $x3, $x4),
            max($y1, $y2, $y3, $y4),
        );
    }

    public function inverse(): self
    {
        $det = ($this->a * $this->d) - ($this->b * $this->c);
        if (abs($det) < 1.0e-12) {
            throw new InvalidArgumentException('Matrix is not invertible.');
        }

        return new self(
            $this->d / $det,
            -$this->b / $det,
            -$this->c / $det,
            $this->a / $det,
            (($this->c * $this->f) - ($this->d * $this->e)) / $det,
            (($this->b * $this->e) - ($this->a * $this->f)) / $det,
        );
    }

    public function isIdentity(): bool
    {
        return (
            1.0 === $this->a
                && 0.0 === $this->b
                && 0.0 === $this->c
                && 1.0 === $this->d
                && 0.0 === $this->e
                && 0.0 === $this->f
        );
    }

    public function rotate(float $degrees): self
    {
        return self::rotation($degrees)->then($this);
    }

    public function scale(float $sx, ?float $sy = null): self
    {
        return self::scaling($sx, $sy)->then($this);
    }

    /** Applies this matrix first, then $next. */
    public function then(self $next): self
    {
        return new self(
            ($this->a * $next->a) + ($this->b * $next->c),
            ($this->a * $next->b) + ($this->b * $next->d),
            ($this->c * $next->a) + ($this->d * $next->c),
            ($this->c * $next->b) + ($this->d * $next->d),
            ($this->e * $next->a) + ($this->f * $next->c) + $next->e,
            ($this->e * $next->b) + ($this->f * $next->d) + $next->f,
        );
    }

    /** @return list<float> */
    public function toArray(): array
    {
        return [$this->a, $this->b, $this->c, $this->d, $this->e, $this->f];
    }

    public function toPdf(): string
    {
        return Num::list($this->toArray(), 6);
    }

    public function translate(float $tx, float $ty): self
    {
        return self::translation($tx, $ty)->then($this);
    }
}
