<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use function count;

/**
 * The positions of the glyphs being shaped, changed in place as the positioning lookups apply:
 * each glyph's x and y advance and offset in font units, and the glyph it attaches to (relative)
 * and how.
 *
 * @phpstan-type Position array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}
 *
 * @internal
 */
final class GlyphPositions
{
    /** @param list<Position> $positions */
    public function __construct(
        private array $positions,
    ) {}

    /** @return list<Position> */
    public function all(): array
    {
        return $this->positions;
    }

    public function count(): int
    {
        return count($this->positions);
    }

    /** @return Position */
    public function get(int $i): array
    {
        return $this->positions[$i] ?? [0, 0, 0, 0, 0, 0];
    }

    /** @param Position $position */
    public function set(int $i, array $position): void
    {
        if ($i < 0 || $i >= count($this->positions)) {
            return;
        }
        $this->positions[$i] = $position;
    }
}
