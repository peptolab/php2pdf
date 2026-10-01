<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Style;

/**
 * A tab stop at a position measured from the paragraph's left indent.
 *
 * @api
 */
final readonly class TabStop
{
    public function __construct(
        public float $position,
        public TabAlignment $alignment = TabAlignment::Left,
        public string $decimalSeparator = '.',
    ) {}

    public static function center(float $position): self
    {
        return new self($position, TabAlignment::Center);
    }

    public static function decimal(float $position, string $separator = '.'): self
    {
        return new self($position, TabAlignment::Decimal, $separator);
    }

    public static function left(float $position): self
    {
        return new self($position);
    }

    public static function right(float $position): self
    {
        return new self($position, TabAlignment::Right);
    }
}
