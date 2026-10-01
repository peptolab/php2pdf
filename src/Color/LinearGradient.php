<?php

declare(strict_types=1);

namespace PhpPdf\Color;

use PhpPdf\Core\Object\Dictionary;

/**
 * An axial gradient from (x0, y0) to (x1, y1) in user space at the time the fill is set.
 *
 * @api
 */
final readonly class LinearGradient extends Gradient
{
    /** @param list<GradientStop> $stops */
    public function __construct(
        public float $x0,
        public float $y0,
        public float $x1,
        public float $y1,
        array $stops,
        bool $extend = true,
    ) {
        parent::__construct($stops, $extend);
    }

    #[\Override]
    public function toShading(): Dictionary
    {
        return $this->baseShading(2)->set('Coords', [$this->x0, $this->y0, $this->x1, $this->y1]);
    }
}
