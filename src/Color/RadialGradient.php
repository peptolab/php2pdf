<?php

declare(strict_types=1);

namespace PhpPdf\Color;

use Override;
use PhpPdf\Core\Object\Dictionary;

use function max;
use function min;

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
        GradientSpread $spread = GradientSpread::Pad,
        float $from = 0.0,
        float $to = 1.0,
    ) {
        parent::__construct($stops, $extend, $spread, $from, $to);
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
        [$from, $to] = $this->domain();

        return $this->baseShading(3)->set('Coords', [...$this->circleAt($from), ...$this->circleAt($to)]);
    }

    /** The covered stretch, stopping where the radius would fall below zero. */
    #[Override]
    protected function domain(): array
    {
        [$from, $to] = parent::domain();
        $growth = $this->r1 - $this->r0;
        if ($growth > 0.0) {
            $from = max($from, -$this->r0 / $growth);
        }
        if ($growth < 0.0) {
            $to = min($to, -$this->r0 / $growth);
        }

        return [$from, $to];
    }

    /**
     * The circle at a point along the gradient: its centre and radius.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private function circleAt(float $t): array
    {
        return [
            $this->x0 + ($t * ($this->x1 - $this->x0)),
            $this->y0 + ($t * ($this->y1 - $this->y0)),
            $this->r0 + ($t * ($this->r1 - $this->r0)),
        ];
    }
}
