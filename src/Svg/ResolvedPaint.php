<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Color\AbstractGradient;
use Php2Pdf\Color\ColorInterface;
use Php2Pdf\Geometry\Matrix;

/**
 * What a fill or stroke paints with once its paint server is looked up: nothing, a colour, a
 * gradient or a pattern tile.
 *
 * @internal
 */
final readonly class ResolvedPaint
{
    private function __construct(
        private ?ColorInterface $colour = null,
        private ?AbstractGradient $gradient = null,
        private ?Matrix $gradientMatrix = null,
        private ?PatternTile $pattern = null,
    ) {}

    public static function colour(ColorInterface $colour): self
    {
        return new self(colour: $colour);
    }

    public static function gradient(AbstractGradient $gradient, Matrix $matrix): self
    {
        return new self(
            gradient: $gradient,
            gradientMatrix: $matrix,
        );
    }

    public static function none(): self
    {
        return new self();
    }

    public static function pattern(PatternTile $tile): self
    {
        return new self(pattern: $tile);
    }

    /** Sets the canvas's fill to this paint; returns false, setting nothing, for no paint. */
    public function fill(Canvas $canvas): bool
    {
        match (true) {
            null !== $this->pattern => $canvas->setFillPattern(
                $this->pattern->template,
                $this->pattern->xStep,
                $this->pattern->yStep,
                $this->pattern->matrix,
            ),
            null !== $this->gradient => $canvas->setFillGradient($this->gradient, $this->gradientMatrix),
            null !== $this->colour => $canvas->setFillColor($this->colour),
            default => null,
        };

        return ! $this->isNone();
    }

    /** Sets the canvas's stroke to this paint; returns false, setting nothing, for no paint. */
    public function stroke(Canvas $canvas): bool
    {
        match (true) {
            null !== $this->pattern => $canvas->setStrokePattern(
                $this->pattern->template,
                $this->pattern->xStep,
                $this->pattern->yStep,
                $this->pattern->matrix,
            ),
            null !== $this->gradient => $canvas->setStrokeGradient($this->gradient, $this->gradientMatrix),
            null !== $this->colour => $canvas->setStrokeColor($this->colour),
            default => null,
        };

        return ! $this->isNone();
    }

    private function isNone(): bool
    {
        return null === $this->colour && null === $this->gradient && null === $this->pattern;
    }
}
