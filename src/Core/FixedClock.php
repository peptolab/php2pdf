<?php

declare(strict_types=1);

namespace Php2Pdf\Core;

use DateTimeImmutable;
use Override;

/**
 * A clock that always tells the same time, for documents that must come out byte for byte the
 * same: their creation date, document ID and subset tags do not change between runs.
 *
 * @api
 */
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
