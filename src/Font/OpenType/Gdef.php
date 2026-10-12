<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use IntlChar;
use Php2Pdf\Core\BinaryReader;
use Php2Pdf\Exception\ParseException;

use function array_key_exists;
use function class_exists;
use function in_array;
use function ksort;
use function mb_chr;
use function preg_match;

/**
 * The glyph definitions a font's layout lookups consult: each glyph's class (base, ligature, mark
 * or component), the mark attachment classes, and the mark glyph sets lookups can filter by. A
 * font without glyph classes has them synthesised from its cmap, as HarfBuzz synthesises them
 * from characters: a glyph is a mark when the first character mapped to it is one (Unicode
 * category M), and a base otherwise. The classes are built once, on first use.
 *
 * @internal
 */
final class Gdef
{
    public const int BASE      = 1;
    public const int LIGATURE  = 2;
    public const int MARK      = 3;
    public const int COMPONENT = 4;

    /** @var array<int, int>|null glyph => class; null until synthesised from the cmap */
    private ?array $classes;

    /** @var array<int, int> glyph => mark attachment class */
    private readonly array $attachClasses;

    /** @var list<array<int, int>> mark glyph set => glyph => coverage index */
    private readonly array $markSets;

    /**
     * @param array<int, int> $cmap code point => glyph, for synthesising classes
     *
     * @throws ParseException
     */
    public function __construct(
        ?BinaryReader $gdef,
        private readonly array $cmap = [],
    ) {
        if (null === $gdef) {
            $this->classes       = null;
            $this->attachClasses = [];
            $this->markSets      = [];

            return;
        }
        $major               = $gdef->u16(0);
        $minor               = $gdef->u16(2);
        $classOffset         = $gdef->u16(4);
        $attachOffset        = $gdef->u16(10);
        $classes             = 0 === $classOffset ? [] : LayoutTables::readClassDef($gdef, $classOffset);
        $this->classes       = [] === $classes ? null : $classes;
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

    /** Whether a character is a combining mark (Unicode category M). */
    private static function isMarkCharacter(int $codePoint): bool
    {
        if (! class_exists(IntlChar::class)) {
            return 1 === preg_match('/^\p{M}$/u', mb_chr($codePoint, encoding: 'UTF-8'));
        }

        return in_array(
            IntlChar::charType($codePoint),
            [
                IntlChar::CHAR_CATEGORY_NON_SPACING_MARK,
                IntlChar::CHAR_CATEGORY_ENCLOSING_MARK,
                IntlChar::CHAR_CATEGORY_COMBINING_SPACING_MARK,
            ],
            strict: true,
        );
    }

    /**
     * Each glyph's class, as glyphClass() gives it: for reading many glyphs' classes at once.
     *
     * @return array<int, int> glyph => class
     */
    public function classMap(): array
    {
        return $this->classes();
    }

    /** The glyph's class: BASE, LIGATURE, MARK or COMPONENT, or 0 when the font does not say. */
    public function glyphClass(int $glyph): int
    {
        return $this->classes()[$glyph] ?? 0;
    }

    public function inMarkSet(int $set, int $glyph): bool
    {
        return array_key_exists($glyph, $this->markSets[$set] ?? []);
    }

    public function isMark(int $glyph): bool
    {
        return self::MARK === ($this->classes()[$glyph] ?? 0);
    }

    public function markAttachClass(int $glyph): int
    {
        return $this->attachClasses[$glyph] ?? 0;
    }

    /** @return array<int, int> */
    private function classes(): array
    {
        if (null !== $this->classes) {
            return $this->classes;
        }
        $classes = [];
        $cmap    = $this->cmap;
        ksort($cmap);
        foreach ($cmap as $codePoint => $glyph) {
            $classes[$glyph] ??= self::isMarkCharacter($codePoint) ? self::MARK : self::BASE;
        }

        return $this->classes = $classes;
    }
}
