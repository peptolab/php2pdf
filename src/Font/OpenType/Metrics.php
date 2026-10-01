<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

/**
 * Vertical metrics and style facts of a font program, in font units.
 *
 * @api
 */
final readonly class Metrics
{
    /** @param array{0: int, 1: int, 2: int, 3: int} $bbox xMin, yMin, xMax, yMax */
    public function __construct(
        public int $unitsPerEm,
        public int $ascender,
        public int $descender,
        public int $lineGap,
        public int $capHeight,
        public int $xHeight,
        public int $underlinePosition,
        public int $underlineThickness,
        public int $strikeoutPosition,
        public int $strikeoutSize,
        public float $italicAngle,
        public int $weightClass,
        public array $bbox,
        public bool $isFixedPitch,
        public bool $isItalic,
        public bool $isBold,
        public bool $isSerif,
    ) {}

    /** Scales a value in font units to points at the given size. */
    public function scale(int|float $units, float $size): float
    {
        return ($units * $size) / $this->unitsPerEm;
    }
}
