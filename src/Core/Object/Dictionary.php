<?php

declare(strict_types=1);

namespace Php2Pdf\Core\Object;

use function array_key_exists;
use function count;

/**
 * A PDF dictionary. Keys are stored without the leading slash; insertion order is preserved
 * so output is deterministic.
 *
 * @api
 */
final class Dictionary
{
    /** @var array<string, mixed> */
    private array $entries = [];

    /** @param array<string, mixed> $entries */
    public function __construct(array $entries = [])
    {
        // @mago-expect analysis:mixed-assignment
        foreach ($entries as $key => $value) {
            $this->set($key, $value);
        }
    }

    /** @param array<string, mixed> $entries */
    public static function of(array $entries): self
    {
        return new self($entries);
    }

    /** @return array<string, mixed> */
    public function all(): array
    {
        return $this->entries;
    }

    public function count(): int
    {
        return count($this->entries);
    }

    public function get(string $key): mixed
    {
        return $this->entries[$key] ?? null;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->entries);
    }

    public function remove(string $key): self
    {
        unset($this->entries[$key]);

        return $this;
    }

    /**
     * Sets an entry. Passing null removes the key, which keeps optional entries terse.
     */
    public function set(string $key, mixed $value): self
    {
        if (null === $value) {
            unset($this->entries[$key]);

            return $this;
        }
        $this->entries[$key] = $value;

        return $this;
    }
}
