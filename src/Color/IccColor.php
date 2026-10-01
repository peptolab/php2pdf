<?php

declare(strict_types=1);

namespace PhpPdf\Color;

use PhpPdf\Exception\InvalidArgumentException;

/**
 * A colour in an ICCBased colour space.
 *
 * @api
 */
final readonly class IccColor implements Color
{
    /** @var list<float> */
    public array $values;

    public function __construct(public IccProfile $profile, float ...$values)
    {
        if (count($values) !== $profile->componentCount) {
            throw new InvalidArgumentException(sprintf(
                'Profile has %d components, %d given.',
                $profile->componentCount,
                count($values),
            ));
        }
        $this->values = array_values($values);
    }

    #[\Override]
    public function componentCount(): int
    {
        return $this->profile->componentCount;
    }

    #[\Override]
    public function components(): array
    {
        return $this->values;
    }
}
