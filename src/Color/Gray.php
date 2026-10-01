<?php

declare(strict_types=1);

namespace PhpPdf\Color;

/**
 * DeviceGray, 0.0 (black) to 1.0 (white).
 *
 * @api
 */
final readonly class Gray implements Color
{
    public function __construct(public float $gray)
    {
    }

    public static function black(): self
    {
        return new self(0.0);
    }

    public static function white(): self
    {
        return new self(1.0);
    }

    #[\Override]
    public function componentCount(): int
    {
        return 1;
    }

    #[\Override]
    public function components(): array
    {
        return [$this->gray];
    }
}
