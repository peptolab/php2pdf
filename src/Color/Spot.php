<?php

declare(strict_types=1);

namespace PhpPdf\Color;

use PhpPdf\Exception\InvalidArgumentException;

/**
 * A spot (Separation) colour such as "PANTONE 300 C", with an alternate colour for display and a
 * tint from 0.0 to 1.0.
 *
 * @api
 */
final readonly class Spot implements Color
{
    public function __construct(
        public string $name,
        public Gray|Rgb|Cmyk $alternate,
        public float $tint = 1.0,
    ) {
        if ('' === $name) {
            throw new InvalidArgumentException('Spot colour name must not be empty.');
        }
    }

    public function withTint(float $tint): self
    {
        return new self($this->name, $this->alternate, $tint);
    }

    #[\Override]
    public function componentCount(): int
    {
        return 1;
    }

    #[\Override]
    public function components(): array
    {
        return [$this->tint];
    }
}
