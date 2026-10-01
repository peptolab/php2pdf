<?php

declare(strict_types=1);

namespace Php2Pdf\Core;

use DateTimeImmutable;

/**
 * Source of the current time. Inject a FixedClock to make output byte-identical across runs.
 *
 * @api
 */
interface ClockInterface
{
    public function now(): DateTimeImmutable;
}
