<?php

declare(strict_types=1);

namespace Php2Pdf\Core;

use DateTimeImmutable;
use Override;

/**
 * The clock documents use by default: the current time.
 *
 * @api
 */
final class SystemClock implements ClockInterface
{
    #[Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
