<?php

declare(strict_types=1);

namespace Php2Pdf\Canvas;

use Php2Pdf\Color\AbstractGradient;
use Php2Pdf\Color\ColorInterface;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Objects;
use Php2Pdf\Core\Serializer;
use Php2Pdf\Geometry\Matrix;
use Php2Pdf\Template\Template;

/**
 * What a canvas paints with, written once per document and named in the canvas's resources:
 * colours, graphics state parameters (opacity, blend mode, overprint, soft masks), gradients as
 * shadings and shading patterns, and tiling patterns.
 *
 * @internal
 */
final readonly class Paint
{
    public function __construct(
        private Objects $objects,
        private Resources $resources,
        private ColourOperators $colours,
    ) {}

    /**
     * The operation that sets a colour.
     *
     * @param array{string, string, string, string, string} $ops gray, rgb, cmyk, colour-space and component operators
     */
    public function colour(ColorInterface $color, array $ops): string
    {
        return $this->colours->ops($color, $ops);
    }

    /**
     * The resource name of a graphics state with the given entries.
     *
     * @param array<string, mixed> $entries
     */
    public function extGState(array $entries): string
    {
        $dict = Dictionary::of(['Type' => new Name('ExtGState'), ...$entries]);
        $ref  = $this->objects->shared('gs:' . Serializer::serialize($dict), static fn(): Dictionary => $dict);

        return $this->resources->extGState($ref);
    }

    /** The resource name of a gradient as a shading pattern, its coordinates mapped by $matrix. */
    public function gradientPattern(AbstractGradient $gradient, Matrix $matrix): string
    {
        $shading = $this->colours->shading($gradient);
        $pattern = $this->objects->shared(
            "pat:{$gradient->key()}:{$matrix->toPdf()}",
            static fn(): Dictionary => Dictionary::of([
                'PatternType' => 2,
                'Shading'     => $shading,
                'Matrix'      => $matrix->toArray(),
            ]),
        );

        return $this->resources->pattern($pattern);
    }

    /** The resource name of a gradient as a shading, painted with sh. */
    public function shading(AbstractGradient $gradient): string
    {
        return $this->resources->shading($this->colours->shading($gradient));
    }

    /** The resource name of a tiling pattern of a template, its coordinates mapped by $matrix. */
    public function tilePattern(Template $tile, float $xStep, float $yStep, Matrix $matrix): string
    {
        return $this->resources->pattern($this->colours->tilingPattern($tile, $xStep, $yStep, $matrix));
    }
}
