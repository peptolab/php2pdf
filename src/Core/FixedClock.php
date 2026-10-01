<?php

declare(strict_types=1);

namespace PhpPdf\Core;

final readonly class FixedClock implements Clock
{
    private \DateTimeImmutable $time;

    public function __construct(\DateTimeImmutable|string $time)
    {
        $this->time = $time instanceof \DateTimeImmutable ? $time : new \DateTimeImmutable($time);
    }

    public function now(): \DateTimeImmutable
    {
        return $this->time;
    }
}
