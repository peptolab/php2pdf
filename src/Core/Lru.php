<?php

declare(strict_types=1);

namespace Php2Pdf\Core;

use function array_key_first;
use function count;

use const PHP_INT_MAX;

/**
 * A least-recently-used cache bounded by entry count and by total weight (such as bytes), for
 * process-wide caches that must not grow without bound in long-running workers. The newest
 * entry is always kept, even when it alone is over the weight limit.
 *
 * @internal
 *
 * @template T
 */
final class Lru
{
    /** @var array<string, array{0: T, 1: int}> key => value and weight, oldest first */
    private array $entries = [];

    private int $weight = 0;

    public function __construct(
        private readonly int $maxEntries,
        private readonly int $maxWeight = PHP_INT_MAX,
    ) {}

    public function clear(): void
    {
        $this->entries = [];
        $this->weight  = 0;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /** @return T|null */
    public function get(string $key): mixed
    {
        $entry = $this->entries[$key] ?? null;
        if (null === $entry) {
            return null;
        }
        unset($this->entries[$key]);
        $this->entries[$key] = $entry;

        return $entry[0];
    }

    /** @param T $value */
    public function set(string $key, mixed $value, int $weight = 1): void
    {
        $this->remove($key);
        $this->entries[$key] = [$value, $weight];
        $this->weight        += $weight;
        while (
            count($this->entries) > 1
            && (
                count($this->entries) > $this->maxEntries
                || $this->weight > $this->maxWeight
            )
        ) {
            $this->remove(array_key_first($this->entries));
        }
    }

    public function weight(): int
    {
        return $this->weight;
    }

    private function remove(string $key): void
    {
        $entry = $this->entries[$key] ?? null;
        if (null === $entry) {
            return;
        }
        $this->weight -= $entry[1];
        unset($this->entries[$key]);
    }
}
