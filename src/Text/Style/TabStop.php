<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Style;

use Php2Pdf\Exception\InvalidArgumentException;

/**
 * A tab stop at a position measured from the paragraph's left indent. A leader (such as '.' or
 * '. ') is repeated across the space the tab jumps, in the tab's text style; its repeats sit on a
 * grid of their own width from the start of the line, so leaders on successive lines line up.
 *
 * @api
 */
final readonly class TabStop
{
    public function __construct(
        public float $position,
        public TabAlignment $alignment = TabAlignment::Left,
        public string $decimalSeparator = '.',
        public ?string $leader = null,
    ) {
        if ('' === $leader) {
            throw new InvalidArgumentException('A tab leader must have at least one character; use null for none.');
        }
    }

    public static function center(float $position, ?string $leader = null): self
    {
        return new self($position, TabAlignment::Center, leader: $leader);
    }

    public static function decimal(float $position, string $separator = '.', ?string $leader = null): self
    {
        return new self($position, TabAlignment::Decimal, $separator, $leader);
    }

    public static function left(float $position, ?string $leader = null): self
    {
        return new self($position, leader: $leader);
    }

    public static function right(float $position, ?string $leader = null): self
    {
        return new self($position, TabAlignment::Right, leader: $leader);
    }
}
