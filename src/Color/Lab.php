<?php

declare(strict_types=1);

namespace Php2Pdf\Color;

use Override;
use Php2Pdf\Exception\InvalidArgumentException;

use function sprintf;

/**
 * CIE L*a*b* under the D50 white point, as colour books define spot inks: lightness from 0 to
 * 100, a* and b* from -128 to 127. Device independent, so the output intent renders it.
 *
 * @api
 */
final readonly class Lab implements ColorInterface
{
    public function __construct(
        public float $lightness,
        public float $a,
        public float $b,
    ) {
        if ($lightness < 0.0 || $lightness > 100.0 || $a < -128.0 || $a > 127.0 || $b < -128.0 || $b > 127.0) {
            throw new InvalidArgumentException(sprintf(
                'Lab values must have L* from 0 to 100 and a*, b* from -128 to 127, not %s, %s, %s.',
                $lightness,
                $a,
                $b,
            ));
        }
    }

    #[Override]
    public function componentCount(): int
    {
        return 3;
    }

    #[Override]
    public function components(): array
    {
        return [$this->lightness, $this->a, $this->b];
    }
}
