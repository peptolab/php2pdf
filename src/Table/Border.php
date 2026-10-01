<?php

declare(strict_types=1);

namespace Php2Pdf\Table;

use Php2Pdf\Color\ColorInterface;
use Php2Pdf\Color\Gray;
use Php2Pdf\Exception\InvalidArgumentException;

/**
 * A cell edge: line width in points, colour and dash pattern. A width of zero draws nothing.
 *
 * @api
 */
final readonly class Border
{
    public ColorInterface $color;

    /** @param list<float> $dash */
    public function __construct(
        public float $width = 0.5,
        ?ColorInterface $color = null,
        public array $dash = [],
    ) {
        if ($width < 0.0) {
            throw new InvalidArgumentException('Border width cannot be negative.');
        }
        $this->color = $color ?? Gray::black();
    }

    public static function none(): self
    {
        return new self(0.0);
    }

    public function isVisible(): bool
    {
        return $this->width > 0.0;
    }
}
