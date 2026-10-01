<?php

declare(strict_types=1);

namespace PhpPdf\Color;

/**
 * DeviceCMYK with components from 0.0 to 1.0.
 *
 * @api
 */
final readonly class Cmyk implements Color
{
    public function __construct(
        public float $cyan,
        public float $magenta,
        public float $yellow,
        public float $black,
    ) {
    }

    /** From 0–100 percentages. */
    public static function fromPercent(float $cyan, float $magenta, float $yellow, float $black): self
    {
        return new self($cyan / 100.0, $magenta / 100.0, $yellow / 100.0, $black / 100.0);
    }

    #[\Override]
    public function componentCount(): int
    {
        return 4;
    }

    #[\Override]
    public function components(): array
    {
        return [$this->cyan, $this->magenta, $this->yellow, $this->black];
    }
}
