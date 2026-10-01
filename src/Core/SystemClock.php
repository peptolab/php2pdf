<?php

declare(strict_types=1);

namespace Php2Pdf\Core;

use DateTimeImmutable;
use Override;

final class SystemClock implements ClockInterface
{
    #[Override]
    public function now(): DateTimeImmutable
    {
        return new DateTimeImmutable('now');
    }
}
