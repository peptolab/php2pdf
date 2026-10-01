<?php

declare(strict_types=1);

namespace PhpPdf\Core;

use DateTimeImmutable;
use Override;

final readonly class FixedClock implements ClockInterface
{
    private DateTimeImmutable $time;

    public function __construct(DateTimeImmutable|string $time)
    {
        $this->time = $time instanceof DateTimeImmutable ? $time : new DateTimeImmutable($time);
    }

    #[Override]
    public function now(): DateTimeImmutable
    {
        return $this->time;
    }
}
