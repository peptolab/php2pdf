<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use Php2Pdf\Exception\ParseException;
use Php2Pdf\Font\TextDirection;

use function array_key_exists;
use function min;

/**
 * GPOS attachment lookups: mark-to-base, mark-to-ligature and mark-to-mark attachment, and
 * cursive attachment, as HarfBuzz applies them. Each records in the glyph's position the glyph it
 * attaches to (relative index) and how (MARK or CURSIVE), for Gpos to turn into final offsets.
 *
 * @phpstan-type Entry array{0: int, 1: list<int>, 2?: int, 3?: int, 4?: string}
 * @phpstan-type Position array{0: int, 1: int, 2: int, 3: int, 4: int, 5: int}
 *
 * @internal
 */
final readonly class GposAttachment
{
    /** How a glyph is attached: to a base, ligature or mark, or cursively. */
    public const int MARK    = 1;
    public const int CURSIVE = 2;

    public function __construct(
        private LayoutTables $tables,
        private Gdef $gdef,
    ) {}

    /**
     * Attaches the glyph at $i to the one at $base: its offset puts its anchor on the other's.
     *
     * @param list<Position>         $positions
     * @param array{0: int, 1: int} $baseAnchor
     * @param array{0: int, 1: int} $markAnchor
     *
     * @return list<Position>
     */
    private static function attach(array $positions, int $i, int $base, array $baseAnchor, array $markAnchor): array
    {
        $p             = $positions[$i] ?? [0, 0, 0, 0, 0, 0];
        $positions[$i] = [
            $p[0],
            $p[1],
            $baseAnchor[0] - $markAnchor[0],
            $baseAnchor[1] - $markAnchor[1],
            $base - $i,
            self::MARK,
        ];

        return $positions;
    }

    /**
     * Joins a glyph's entry to the previous glyph's exit: in right-to-left text the previous glyph
     * moves, in left-to-right text this one does.
     *
     * @param Position $previous
     * @param Position $current
     *
     * @return array{0: Position, 1: Position}
     */
    private static function join(
        array $previous,
        array $current,
        int $exitX,
        int $entryX,
        TextDirection $direction,
    ): array {
        if (TextDirection::RightToLeft === $direction) {
            $d           = $exitX + $previous[2];
            $previous[0] -= $d;
            $previous[2] -= $d;
            $current[0]  = $entryX + $current[2];

            return [$previous, $current];
        }
        $previous[0] = $exitX + $previous[2];
        $d           = $entryX + $current[2];
        $current[0]  -= $d;
        $current[2]  -= $d;

        return [$previous, $current];
    }

    /**
     * Turns round the cursive chain a glyph already hangs in, so the whole chain hangs from the
     * glyph's new parent, stopping at that parent.
     *
     * @param list<Position> $positions
     *
     * @return list<Position>
     */
    private static function reverseCursiveChain(array $positions, int $i, int $newParent): array
    {
        $p     = $positions[$i] ?? [0, 0, 0, 0, 0, 0];
        $chain = $p[4];
        if (0 === $chain || self::CURSIVE !== $p[5]) {
            return $positions;
        }
        $positions[$i] = [$p[0], $p[1], $p[2], $p[3], 0, $p[5]];
        $j             = $i + $chain;
        if ($j === $newParent) {
            return $positions;
        }
        $positions     = self::reverseCursiveChain($positions, $j, $newParent);
        $q             = $positions[$j] ?? [0, 0, 0, 0, 0, 0];
        $positions[$j] = [$q[0], $q[1], $q[2], -$p[3], -$chain, $p[5]];

        return $positions;
    }

    /**
     * Whether two marks may attach: both outside ligatures, on the same ligature component, or
     * one of them on a ligature as a whole.
     *
     * @param list<Entry> $glyphs
     */
    private static function sameComponent(array $glyphs, int $i, int $j): bool
    {
        $id1   = $glyphs[$i][2] ?? 0;
        $id2   = $glyphs[$j][2] ?? 0;
        $comp1 = $glyphs[$i][3] ?? 0;
        $comp2 = $glyphs[$j][3] ?? 0;
        if ($id1 === $id2) {
            return 0 === $id1 || $comp1 === $comp2;
        }

        return ($id1 > 0 && 0 === $comp1) || ($id2 > 0 && 0 === $comp2);
    }

    /**
     * Cursive attachment, as HarfBuzz does it: the glyph's entry anchor joins the exit anchor of
     * the glyph before it, the advance between them ending at the anchors; one hangs from the
     * other vertically (the earlier from the later under the right-to-left lookup flag), and a
     * chain it already hung in is turned round to hang from its new parent.
     *
     * @param list<int>      $ids
     * @param list<Position> $positions
     *
     * @return array{0: list<Position>, 1: int}|null
     *
     * @throws ParseException
     */
    public function cursive(
        int $offset,
        array $ids,
        array $positions,
        int $i,
        GlyphFilter $filter,
        TextDirection $direction,
    ): ?array {
        $r        = $this->tables->reader;
        $coverage = $this->tables->coverage($offset + $r->u16($offset + 2));
        $index    = $coverage[$ids[$i] ?? -1] ?? null;
        $entry    = null === $index ? 0 : $r->u16($offset + 6 + (4 * $index));
        $prev     = 0 === $entry ? null : ChainRules::previous($ids, $i, $filter);
        $exit     = null === $prev ? 0 : $r->u16($offset + 8 + (4 * ($coverage[$ids[$prev] ?? -1] ?? -1)));
        if (null === $prev || 0 === $exit || ! array_key_exists($ids[$prev] ?? -1, $coverage)) {
            return null;
        }
        [$exitX, $exitY] = $this->anchor($offset + $exit);
        [$entryX, $entryY] = $this->anchor($offset + $entry);
        $p = $positions[$prev] ?? [0, 0, 0, 0, 0, 0];
        $c = $positions[$i] ?? [0, 0, 0, 0, 0, 0];
        [$p, $c] = self::join($p, $c, $exitX, $entryX, $direction);
        $positions[$prev] = $p;
        $positions[$i]    = $c;
        $rightToLeftFlag  = 0 !== ($filter->flag & GlyphFilter::RIGHT_TO_LEFT);
        [$child, $parent] = $rightToLeftFlag ? [$prev, $i] : [$i, $prev];
        $yOffset           = $rightToLeftFlag ? $entryY - $exitY : $exitY - $entryY;
        $positions         = self::reverseCursiveChain($positions, $child, $parent);
        $own               = $positions[$child] ?? [0, 0, 0, 0, 0, 0];
        $positions[$child] = [$own[0], $own[1], $own[2], $yOffset, $parent - $child, self::CURSIVE];
        $parentPosition    = $positions[$parent] ?? [0, 0, 0, 0, 0, 0];
        if ($parentPosition[4] === ($child - $parent)) {
            $positions[$parent] = [
                $parentPosition[0],
                $parentPosition[1],
                $parentPosition[2],
                0,
                0,
                $parentPosition[5],
            ];
        }

        return [$positions, $i + 1];
    }

    /**
     * Mark-to-base attachment: the mark at $i attaches to the base before it, passing over marks.
     *
     * @param list<Entry>    $glyphs
     * @param list<int>      $ids
     * @param list<Position> $positions
     *
     * @return array{0: list<Position>, 1: int}|null
     *
     * @throws ParseException
     */
    public function markToBase(
        int $offset,
        array $glyphs,
        array $ids,
        array $positions,
        int $i,
        GlyphFilter $filter,
    ): ?array {
        $r    = $this->tables->reader;
        $mark = $this->tables->coverage($offset + $r->u16($offset + 2))[$ids[$i] ?? -1] ?? null;
        if (null === $mark) {
            return null;
        }
        $j = ChainRules::previous($ids, $i, $filter->marksOnly());
        while (null !== $j && $this->isLaterComponent($glyphs, $j)) {
            $j = ChainRules::previous($ids, $j, $filter->marksOnly());
        }
        $base = null === $j ? null : $this->tables->coverage($offset + $r->u16($offset + 4))[$ids[$j] ?? -1] ?? null;
        if (null === $j || null === $base) {
            return null;
        }
        $classes   = $r->u16($offset + 6);
        $markArray = $offset + $r->u16($offset + 8);
        $baseArray = $offset + $r->u16($offset + 10);
        $record    = $markArray + 2 + (4 * $mark);
        $class     = $r->u16($record);
        $anchor    = $r->u16($baseArray + 2 + (2 * (($base * $classes) + $class)));
        if (0 === $anchor || $class >= $classes) {
            return null;
        }

        return [
            self::attach(
                $positions,
                $i,
                $j,
                $this->anchor($baseArray + $anchor),
                $this->anchor($markArray + $r->u16($record + 2)),
            ),
            $i + 1,
        ];
    }

    /**
     * Mark-to-ligature attachment: the mark attaches to the ligature component it followed, or to
     * the last component.
     *
     * @param list<Entry>    $glyphs
     * @param list<int>      $ids
     * @param list<Position> $positions
     *
     * @return array{0: list<Position>, 1: int}|null
     *
     * @throws ParseException
     */
    public function markToLigature(
        int $offset,
        array $glyphs,
        array $ids,
        array $positions,
        int $i,
        GlyphFilter $filter,
    ): ?array {
        $r    = $this->tables->reader;
        $mark = $this->tables->coverage($offset + $r->u16($offset + 2))[$ids[$i] ?? -1] ?? null;
        $j    = null === $mark ? null : ChainRules::previous($ids, $i, $filter->marksOnly());
        $lig  = null === $j ? null : $this->tables->coverage($offset + $r->u16($offset + 4))[$ids[$j] ?? -1] ?? null;
        if (null === $mark || null === $j || null === $lig) {
            return null;
        }
        $classes    = $r->u16($offset + 6);
        $markArray  = $offset + $r->u16($offset + 8);
        $ligArray   = $offset + $r->u16($offset + 10);
        $attach     = $ligArray + $r->u16($ligArray + 2 + (2 * $lig));
        $components = $r->u16($attach);
        if (0 === $components) {
            return null;
        }
        $ligId     = $glyphs[$j][2] ?? 0;
        $markLigId = $glyphs[$i][2] ?? 0;
        $markComp  = $glyphs[$i][3] ?? 0;
        $component = 0 !== $ligId && $ligId === $markLigId && $markComp > 0
            ? min($components, $markComp) - 1
            : $components - 1;
        $record = $markArray + 2 + (4 * $mark);
        $class  = $r->u16($record);
        $anchor = $r->u16($attach + 2 + (2 * (($component * $classes) + $class)));
        if (0 === $anchor || $class >= $classes) {
            return null;
        }

        return [
            self::attach(
                $positions,
                $i,
                $j,
                $this->anchor($attach + $anchor),
                $this->anchor($markArray + $r->u16($record + 2)),
            ),
            $i + 1,
        ];
    }

    /**
     * Mark-to-mark attachment: the mark attaches to the mark before it when both belong to the
     * same ligature component, or neither to a ligature.
     *
     * @param list<Entry>    $glyphs
     * @param list<int>      $ids
     * @param list<Position> $positions
     *
     * @return array{0: list<Position>, 1: int}|null
     *
     * @throws ParseException
     */
    public function markToMark(
        int $offset,
        array $glyphs,
        array $ids,
        array $positions,
        int $i,
        GlyphFilter $filter,
    ): ?array {
        $r     = $this->tables->reader;
        $mark1 = $this->tables->coverage($offset + $r->u16($offset + 2))[$ids[$i] ?? -1] ?? null;
        $j     = null === $mark1 ? null : ChainRules::previous($ids, $i, $filter);
        if (
            null === $mark1
            || null === $j
            || ! $this->gdef->isMark($ids[$j] ?? 0)
            || ! self::sameComponent($glyphs, $i, $j)
        ) {
            return null;
        }
        $mark2 = $this->tables->coverage($offset + $r->u16($offset + 4))[$ids[$j] ?? -1] ?? null;
        if (null === $mark2) {
            return null;
        }
        $classes    = $r->u16($offset + 6);
        $mark1Array = $offset + $r->u16($offset + 8);
        $mark2Array = $offset + $r->u16($offset + 10);
        $record     = $mark1Array + 2 + (4 * $mark1);
        $class      = $r->u16($record);
        $anchor     = $r->u16($mark2Array + 2 + (2 * (($mark2 * $classes) + $class)));
        if (0 === $anchor || $class >= $classes) {
            return null;
        }

        return [
            self::attach(
                $positions,
                $i,
                $j,
                $this->anchor($mark2Array + $anchor),
                $this->anchor($mark1Array + $r->u16($record + 2)),
            ),
            $i + 1,
        ];
    }

    /**
     * An anchor's coordinates: formats 1 to 3, without contour points or device tables.
     *
     * @return array{0: int, 1: int}
     *
     * @throws ParseException
     */
    private function anchor(int $offset): array
    {
        $r = $this->tables->reader;

        return [$r->i16($offset + 2), $r->i16($offset + 4)];
    }

    /**
     * Whether the glyph at $j is a later glyph of a multiple substitution, which marks pass over
     * to attach to its first glyph.
     *
     * @param list<Entry> $glyphs
     */
    private function isLaterComponent(array $glyphs, int $j): bool
    {
        return [] === ($glyphs[$j][1] ?? [0]) && $j > 0 && ! $this->gdef->isMark($glyphs[$j - 1][0] ?? 0);
    }
}
