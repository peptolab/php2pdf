<?php

declare(strict_types=1);

namespace PhpPdf\Core\Object;

/**
 * A PDF name object (/Foo). Names are atoms; the value is stored unescaped.
 */
final readonly class Name
{
    public function __construct(public string $value)
    {
    }

    public static function of(string $value): self
    {
        return new self($value);
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
