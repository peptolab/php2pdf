<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use function count;

/**
 * The positions of the glyphs being shaped, changed in place as the positioning lookups apply:
 * each glyph's x and y advance and offset in font units, and the glyph it attaches to (relative)
 * and how. Made from the glyphs' advances, it holds only the positions lookups have given: a glyph
 * no lookup has touched is at its advance, with no offset and attached to nothing.
 *
 * @phpstan-type Position array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}
 *
 * @internal
 */
final class GlyphPositions
{
    /** @var list<int> each glyph's advance, its x advance until a lookup gives it a position */
    private array $advances = [];

    private int $count;

    /** @param array<int, Position> $positions every glyph's position */
    public function __construct(
        private array $positions,
    ) {
        $this->count = count($positions);
    }

    /**
     * Glyphs at their advances, none positioned yet.
     *
     * @param list<int> $advances
     */
    public static function natural(array $advances, int $count): self
    {
        $positions           = new self([]);
        $positions->advances = $advances;
        $positions->count    = $count;

        return $positions;
    }

    /**
     * Adds to a glyph's x and y advance and offset in place; nothing for a position out of range.
     */
    public function add(int $i, int $xAdvance, int $yAdvance, int $xOffset, int $yOffset): void
    {
        if ($i < 0 || $i >= $this->count) {
            return;
        }
        $p                   = $this->positions[$i] ?? [$this->advances[$i] ?? 0, 0, 0, 0, 0, 0];
        $p[0]                += $xAdvance;
        $p[1]                += $yAdvance;
        $p[2]                += $xOffset;
        $p[3]                += $yOffset;
        $this->positions[$i] = $p;
    }

    /**
     * Adds a value record (x and y placement, x and y advance) to a glyph's position, as add().
     *
     * @param array{0: int, 1: int, 2: int, 3: int} $value
     */
    public function adjust(int $i, array $value): void
    {
        if ($i < 0 || $i >= $this->count) {
            return;
        }
        $p                   = $this->positions[$i] ?? [$this->advances[$i] ?? 0, 0, 0, 0, 0, 0];
        $p[0]                += $value[2];
        $p[1]                += $value[3];
        $p[2]                += $value[0];
        $p[3]                += $value[1];
        $this->positions[$i] = $p;
    }

    /** @return list<Position> every glyph's position */
    public function all(): array
    {
        $all = [];
        for ($i = 0; $i < $this->count; ++$i) {
            $all[] = $this->positions[$i] ?? [$this->advances[$i] ?? 0, 0, 0, 0, 0, 0];
        }

        return $all;
    }

    public function count(): int
    {
        return $this->count;
    }

    /** @return Position */
    public function get(int $i): array
    {
        return (
            $this->positions[$i]
                ?? ($i >= 0 && $i < $this->count ? [$this->advances[$i] ?? 0, 0, 0, 0, 0, 0] : [0, 0, 0, 0, 0, 0])
        );
    }

    /**
     * The positions lookups have given, by glyph: every glyph's for positions made whole.
     *
     * @return array<int, Position>
     */
    public function given(): array
    {
        return $this->positions;
    }

    /** @param Position $position */
    public function set(int $i, array $position): void
    {
        if ($i < 0 || $i >= $this->count) {
            return;
        }
        $this->positions[$i] = $position;
    }
}
