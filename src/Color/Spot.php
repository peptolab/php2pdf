<?php

declare(strict_types=1);

namespace Php2Pdf\Color;

use Override;
use Php2Pdf\Exception\InvalidArgumentException;

/**
 * A spot (Separation) colour such as "PANTONE 300 C", with an alternate colour for display and a
 * tint from 0.0 to 1.0. Colour books give the alternate in Lab, which renders through the output
 * intent; a device colour renders as given.
 *
 * @api
 */
final readonly class Spot implements ColorInterface
{
    public function __construct(
        public string $name,
        public Gray|Rgb|Cmyk|Lab $alternate,
        public float $tint = 1.0,
    ) {
        if ('' === $name) {
            throw new InvalidArgumentException('Spot colour name must not be empty.');
        }
    }

    #[Override]
    public function componentCount(): int
    {
        return 1;
    }

    #[Override]
    public function components(): array
    {
        return [$this->tint];
    }

    public function withTint(float $tint): self
    {
        return new self($this->name, $this->alternate, $tint);
    }
}
