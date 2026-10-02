<?php

declare(strict_types=1);

namespace Php2Pdf\Font\HarfBuzz;

use Closure;

/**
 * Frees what HarfBuzz made for one font program (its blob, face and font) when this is released:
 * when the font program is.
 *
 * @internal
 */
final readonly class HarfBuzzHandles
{
    /** @param Closure(): void $release */
    public function __construct(
        private Closure $release,
    ) {}

    public function __destruct()
    {
        ($this->release)();
    }
}
