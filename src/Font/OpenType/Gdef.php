<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use function array_key_exists;

/**
 * The glyph definitions a font's layout lookups consult: each glyph's class (base, ligature, mark
 * or component), the mark attachment classes, and the mark glyph sets lookups can filter by.
 *
 * @internal
 */
final class Gdef
{
    public const int BASE      = 1;
    public const int LIGATURE  = 2;
    public const int MARK      = 3;
    public const int COMPONENT = 4;

    /** @var array<int, int> glyph => class */
    private readonly array $classes;

    /** @var array<int, int> glyph => mark attachment class */
    private readonly array $attachClasses;

    /** @var list<array<int, int>> mark glyph set => glyph => coverage index */
    private readonly array $markSets;

    public function __construct(BinaryReader $gdef)
    {
        $major               = $gdef->u16(0);
        $minor               = $gdef->u16(2);
        $classOffset         = $gdef->u16(4);
        $attachOffset        = $gdef->u16(10);
        $this->classes       = 0 === $classOffset ? [] : LayoutTables::readClassDef($gdef, $classOffset);
        $this->attachClasses = 0 === $attachOffset ? [] : LayoutTables::readClassDef($gdef, $attachOffset);
        $sets                = [];
        $setsOffset          = 1 === $major && $minor >= 2 ? $gdef->u16(12) : 0;
        if (0 !== $setsOffset) {
            for ($k = 0, $count = $gdef->u16($setsOffset + 2); $k < $count; ++$k) {
                $sets[] = LayoutTables::readCoverage($gdef, $setsOffset + $gdef->u32($setsOffset + 4 + (4 * $k)));
            }
        }
        $this->markSets = $sets;
    }

    /** The glyph's class: BASE, LIGATURE, MARK or COMPONENT, or 0 when the font does not say. */
    public function glyphClass(int $glyph): int
    {
        return $this->classes[$glyph] ?? 0;
    }

    public function hasGlyphClasses(): bool
    {
        return [] !== $this->classes;
    }

    public function inMarkSet(int $set, int $glyph): bool
    {
        return array_key_exists($glyph, $this->markSets[$set] ?? []);
    }

    public function isMark(int $glyph): bool
    {
        return self::MARK === ($this->classes[$glyph] ?? 0);
    }

    public function markAttachClass(int $glyph): int
    {
        return $this->attachClasses[$glyph] ?? 0;
    }
}
