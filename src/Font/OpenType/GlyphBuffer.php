<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use function array_key_exists;
use function array_splice;
use function count;

/**
 * The glyphs being shaped, changed in place as the lookups apply: each entry's glyph id, the code
 * points it stands for, the ligature it belongs to and the component it follows, and its Arabic
 * joining form; and the glyph ids alone, kept in step, for matching.
 *
 * A glyph taken into a ligature, or deleted, leaves a placeholder (glyph id REMOVED) that every
 * lookup passes over, so positions do not move while a lookup runs and removing a glyph costs
 * nothing; compact() drops the placeholders between lookups. Only a substitution that adds
 * glyphs moves the ones after it.
 *
 * @phpstan-type Entry array{0: int, 1: list<int>, 2?: int, 3?: int, 4?: string}
 *
 * @internal
 */
final class GlyphBuffer
{
    /** The glyph id of a placeholder. */
    public const int REMOVED = -1;

    /** @var list<Entry> */
    private array $entries;

    /** @var list<int> */
    private array $ids = [];

    private int $ligatures = 0;
    private int $removed   = 0;

    /** @param list<Entry> $entries */
    public function __construct(array $entries)
    {
        $this->entries = $entries;
        foreach ($entries as $entry) {
            $this->ids[] = $entry[0];
        }
    }

    /** Drops the placeholders, so positions count live glyphs again. */
    public function compact(): void
    {
        if (0 === $this->removed) {
            return;
        }
        $entries = [];
        $ids     = [];
        foreach ($this->entries as $entry) {
            if (self::REMOVED === $entry[0]) {
                continue;
            }
            $entries[] = $entry;
            $ids[]     = $entry[0];
        }
        $this->entries = $entries;
        $this->ids     = $ids;
        $this->removed = 0;
    }

    /** The number of positions, placeholders included. */
    public function count(): int
    {
        return count($this->ids);
    }

    /** @return list<Entry> the live glyphs */
    public function entries(): array
    {
        $this->compact();

        return $this->entries;
    }

    /** @return Entry|null */
    public function entry(int $i): ?array
    {
        return $this->entries[$i] ?? null;
    }

    public function id(int $i): ?int
    {
        return $this->ids[$i] ?? null;
    }

    /** @return list<int> the glyph ids, in order, placeholders included */
    public function ids(): array
    {
        return $this->ids;
    }

    /** The number of live glyphs, placeholders left out. */
    public function length(): int
    {
        return count($this->ids) - $this->removed;
    }

    /** A new ligature's id, unique in this run. */
    public function nextLigature(): int
    {
        return ++$this->ligatures;
    }

    /** Leaves a placeholder at $i. */
    public function remove(int $i): void
    {
        if (self::REMOVED === ($this->ids[$i] ?? self::REMOVED)) {
            return;
        }
        $this->entries[$i] = [self::REMOVED, []];
        $this->ids[$i]     = self::REMOVED;
        ++$this->removed;
    }

    /** Replaces the glyph at $i, keeping what it stands for. */
    public function replace(int $i, int $glyph): void
    {
        if (! array_key_exists($i, $this->entries)) {
            return;
        }
        $this->entries[$i][0] = $glyph;
        $this->ids[$i]        = $glyph;
    }

    /**
     * Sets the entry at $i; setting a glyph over a placeholder makes it live again.
     *
     * @param Entry $entry
     */
    public function set(int $i, array $entry): void
    {
        $current = $this->ids[$i] ?? null;
        if (null === $current) {
            return;
        }
        if (self::REMOVED === $current && self::REMOVED !== $entry[0]) {
            --$this->removed;
        }
        if (self::REMOVED !== $current && self::REMOVED === $entry[0]) {
            ++$this->removed;
        }
        $this->entries[$i] = $entry;
        $this->ids[$i]     = $entry[0];
    }

    /**
     * Replaces the glyph at $i with several, moving those after it.
     *
     * @param list<Entry> $replacement
     */
    public function splice(int $i, array $replacement): void
    {
        array_splice($this->entries, $i, length: 1, replacement: $replacement);
        $ids = [];
        foreach ($replacement as $entry) {
            $ids[] = $entry[0];
        }
        array_splice($this->ids, $i, length: 1, replacement: $ids);
    }
}
