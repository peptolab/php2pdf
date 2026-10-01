<?php

declare(strict_types=1);

namespace PhpPdf\Color;

use Override;
use PhpPdf\Core\Object\Dictionary;

/**
 * A radial gradient between two circles in user space at the time the fill is set.
 *
 * @api
 */
final readonly class RadialGradient extends AbstractGradient
{
    /** @param list<GradientStop> $stops */
    public function __construct(
        public float $x0,
        public float $y0,
        public float $r0,
        public float $x1,
        public float $y1,
        public float $r1,
        array $stops,
        bool $extend = true,
    ) {
        parent::__construct($stops, $extend);
    }

    /**
     * A gradient radiating from a single centre out to a radius.
     *
     * @param list<GradientStop> $stops
     */
    public static function circle(float $cx, float $cy, float $radius, array $stops, bool $extend = true): self
    {
        return new self($cx, $cy, 0.0, $cx, $cy, $radius, $stops, $extend);
    }

    #[Override]
    public function toShading(): Dictionary
    {
        return $this->baseShading(3)->set('Coords', [$this->x0, $this->y0, $this->r0, $this->x1, $this->y1, $this->r1]);
    }
}
