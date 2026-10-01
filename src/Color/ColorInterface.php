<?php

declare(strict_types=1);

namespace PhpPdf\Color;

/**
 * A colour value usable for fills and strokes.
 *
 * @api
 */
interface ColorInterface
{
    /** The number of components the colour space has. */
    public function componentCount(): int;

    /** @return list<float> */
    public function components(): array;
}
