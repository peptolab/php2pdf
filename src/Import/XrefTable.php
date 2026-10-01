<?php

declare(strict_types=1);

namespace PhpPdf\Import;

use PhpPdf\Core\Object\Dictionary;

use function array_key_exists;
use function array_keys;

/**
 * Where each object lives: at a byte offset in the file, or at an index inside an object stream.
 * The first entry registered for an object number wins, so sections are added newest first.
 *
 * @internal
 */
final class XrefTable
{
    public const int IN_FILE   = 1;
    public const int IN_STREAM = 2;

    /** @var array<int, array{0: int, 1: int, 2: int}> object number => kind, offset or stream number, generation or index */
    private array $entries = [];

    public readonly Dictionary $trailer;

    public function __construct()
    {
        $this->trailer = new Dictionary();
    }

    public function addInFile(int $number, int $offset, int $generation = 0): void
    {
        $this->entries[$number] ??= [self::IN_FILE, $offset, $generation];
    }

    public function addInStream(int $number, int $streamNumber, int $index): void
    {
        $this->entries[$number] ??= [self::IN_STREAM, $streamNumber, $index];
    }

    /** @return array{0: int, 1: int, 2: int}|null */
    public function entry(int $number): ?array
    {
        return $this->entries[$number] ?? null;
    }

    public function has(int $number): bool
    {
        return array_key_exists($number, $this->entries);
    }

    /** Adds trailer entries that are not yet set (earlier sections never override newer ones). */
    public function mergeTrailer(Dictionary $trailer): void
    {
        foreach ($trailer->all() as $key => $value) {
            if ($this->trailer->has($key)) {
                continue;
            }
            $this->trailer->set($key, $value);
        }
    }

    /** @return list<int> */
    public function numbers(): array
    {
        return array_keys($this->entries);
    }

    /** Replaces any existing entry, as the repair scan does. */
    public function setInFile(int $number, int $offset, int $generation = 0): void
    {
        $this->entries[$number] = [self::IN_FILE, $offset, $generation];
    }
}
