<?php

declare(strict_types=1);

namespace PhpPdf\Color;

use Override;
use PhpPdf\Core\Object\Dictionary;

/**
 * An axial gradient from (x0, y0) to (x1, y1) in user space at the time the fill is set.
 *
 * @api
 */
final readonly class LinearGradient extends AbstractGradient
{
    /** @param list<GradientStop> $stops */
    public function __construct(
        public float $x0,
        public float $y0,
        public float $x1,
        public float $y1,
        array $stops,
        bool $extend = true,
        GradientSpread $spread = GradientSpread::Pad,
        float $from = 0.0,
        float $to = 1.0,
    ) {
        parent::__construct($stops, $extend, $spread, $from, $to);
    }

    #[Override]
    public function toShading(): Dictionary
    {
        [$from, $to] = $this->domain();
        $dx = $this->x1 - $this->x0;
        $dy = $this->y1 - $this->y0;

        return $this->baseShading(2)->set('Coords', [
            $this->x0 + ($from * $dx),
            $this->y0 + ($from * $dy),
            $this->x0 + ($to * $dx),
            $this->y0 + ($to * $dy),
        ]);
    }
}
