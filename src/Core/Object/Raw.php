<?php

declare(strict_types=1);

namespace PhpPdf\Core\Object;

/**
 * Pre-serialised PDF syntax, written verbatim.
 */
final readonly class Raw
{
    public function __construct(
        public string $syntax,
    ) {}
}
