<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Canvas\PlaceableInterface;

/**
 * Artwork set in a line of text: drawn at a size with its bottom edge on the baseline, raised
 * (or lowered) by $raise points.
 *
 * @internal
 */
final readonly class InlineObject
{
    public function __construct(
        public PlaceableInterface $object,
        public float $width,
        public float $height,
        public float $raise = 0.0,
    ) {}

    /**
     * Sized from a width or a height in points keeping the object's proportions, both as given,
     * or one em high when neither is.
     */
    public static function sized(
        PlaceableInterface $object,
        ?float $width,
        ?float $height,
        float $raise,
        float $fontSize,
    ): self {
        $natural = $object->naturalSize();
        $aspect  = $natural->height > 0.0 ? $natural->width / $natural->height : 1.0;
        if (null === $width && null === $height) {
            $height = $fontSize;
        }
        $width  ??= ($height ?? 0.0) * $aspect;
        $height ??= $aspect > 0.0 ? $width / $aspect : $width;

        return new self($object, $width, $height, $raise);
    }

    /** The object at another text size, as copy fitting scales it. */
    public function scaled(float $factor): self
    {
        return new self($this->object, $this->width * $factor, $this->height * $factor, $this->raise * $factor);
    }
}
