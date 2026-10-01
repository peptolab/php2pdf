<?php

declare(strict_types=1);

namespace PhpPdf\Core;

/**
 * Source of the current time. Inject a FixedClock to make output byte-identical across runs.
 */
interface Clock
{
    public function now(): \DateTimeImmutable;
}
