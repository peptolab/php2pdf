<?php

declare(strict_types=1);

namespace Php2Pdf\Svg;

use Php2Pdf\Color\Rgb;

/**
 * A resolved fill or stroke: nothing, a flat colour, or a paint server (gradient) by id with
 * an optional fallback colour.
 *
 * @internal
 */
final readonly class Paint
{
    private function __construct(
        public ?Rgb $color,
        public ?string $serverId,
    ) {}

    public static function color(Rgb $color): self
    {
        return new self($color, null);
    }

    public static function none(): self
    {
        return new self(null, null);
    }

    public static function server(string $id, ?Rgb $fallback): self
    {
        return new self($fallback, $id);
    }

    public function isNone(): bool
    {
        return null === $this->color && null === $this->serverId;
    }
}
