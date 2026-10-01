<?php

declare(strict_types=1);

namespace Php2Pdf\Core\Object;

/**
 * Pre-serialised PDF syntax, written verbatim.
 *
 * @internal
 */
final readonly class Raw
{
    public function __construct(
        public string $syntax,
    ) {}
}
