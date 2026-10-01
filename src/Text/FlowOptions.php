<?php

declare(strict_types=1);

namespace PhpPdf\Text;

use PhpPdf\Exception\InvalidArgumentException;
use PhpPdf\Text\Style\VerticalAlignment;

/**
 * Options for fitting a Textflow into a box.
 *
 * @api
 */
final readonly class FlowOptions
{
    /**
     * @param bool  $shrinkToFit reduce the type size (and leading, indents, spacing) uniformly until the
     *                           remaining text fits the box, but not below $shrinkLimit of the original
     */
    public function __construct(
        public FirstBaseline $firstBaseline = FirstBaseline::Ascender,
        public VerticalAlignment $verticalAlignment = VerticalAlignment::Top,
        public bool $shrinkToFit = false,
        public float $shrinkLimit = 0.5,
    ) {
        if ($shrinkLimit <= 0.0 || $shrinkLimit > 1.0) {
            throw new InvalidArgumentException('The shrink limit must be between 0 and 1.');
        }
    }

    public static function shrinkToFit(float $shrinkLimit = 0.5): self
    {
        return new self(
            shrinkToFit: true,
            shrinkLimit: $shrinkLimit,
        );
    }
}
