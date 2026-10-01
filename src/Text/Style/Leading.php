<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Style;

/**
 * Line spacing, either in points or as a percentage of the font size.
 *
 * @api
 */
final readonly class Leading
{
    private function __construct(
        public float $value,
        public bool $relative,
    ) {}

    public static function percent(float $percent): self
    {
        return new self($percent / 100.0, true);
    }

    public static function pt(float $points): self
    {
        return new self($points, false);
    }

    /** Line spacing in points for a font size. */
    public function resolve(float $fontSize): float
    {
        return $this->relative ? $this->value * $fontSize : $this->value;
    }
}
