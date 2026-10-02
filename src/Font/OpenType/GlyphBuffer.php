<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use function array_key_exists;
use function array_splice;
use function count;

/**
 * The glyphs being shaped, changed in place as the lookups apply: each entry's glyph id, the code
 * points it stands for, the ligature it belongs to and the component it follows, and its Arabic
 * joining form; and the glyph ids alone, kept in step, for matching. Shaping one run changes one
 * buffer, so a substitution costs what it changes rather than a copy of the whole run.
 *
 * @phpstan-type Entry array{0: int, 1: list<int>, 2?: int, 3?: int, 4?: string}
 *
 * @internal
 */
final class GlyphBuffer
{
    /** @var list<Entry> */
    private array $entries;

    /** @var list<int> */
    private array $ids = [];

    private int $ligatures = 0;

    /** @param list<Entry> $entries */
    public function __construct(array $entries)
    {
        $this->entries = $entries;
        foreach ($entries as $entry) {
            $this->ids[] = $entry[0];
        }
    }

    public function count(): int
    {
        return count($this->ids);
    }

    /** @return list<Entry> */
    public function entries(): array
    {
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

    /** @return list<int> the glyph ids, in order */
    public function ids(): array
    {
        return $this->ids;
    }

    /** A new ligature's id, unique in this run. */
    public function nextLigature(): int
    {
        return ++$this->ligatures;
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
     * Replaces $length entries from $i with others.
     *
     * @param list<Entry> $replacement
     */
    public function splice(int $i, int $length, array $replacement): void
    {
        array_splice($this->entries, $i, $length, $replacement);
        $ids = [];
        foreach ($replacement as $entry) {
            $ids[] = $entry[0];
        }
        array_splice($this->ids, $i, $length, $ids);
    }
}
