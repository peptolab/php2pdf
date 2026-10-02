<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

/**
 * Which glyphs a lookup passes over, from its lookup flag and the font's glyph definitions:
 * bases, ligatures or marks it ignores, marks of another attachment class, and marks outside its
 * mark filtering set. Without glyph definitions nothing is ignored.
 *
 * @internal
 */
final readonly class GlyphFilter
{
    public const int RIGHT_TO_LEFT          = 0x0001;
    public const int IGNORE_BASE_GLYPHS     = 0x0002;
    public const int IGNORE_LIGATURES       = 0x0004;
    public const int IGNORE_MARKS           = 0x0008;
    public const int USE_MARK_FILTERING_SET = 0x0010;

    public function __construct(
        private ?Gdef $gdef,
        public int $flag = 0,
        private int $markFilteringSet = 0,
    ) {}

    public function ignores(int $glyph): bool
    {
        if (null === $this->gdef || 0 === ($this->flag & 0xFF1E)) {
            return false;
        }

        return match ($this->gdef->glyphClass($glyph)) {
            Gdef::BASE     => 0 !== ($this->flag & self::IGNORE_BASE_GLYPHS),
            Gdef::LIGATURE => 0 !== ($this->flag & self::IGNORE_LIGATURES),
            Gdef::MARK     => $this->ignoresMark($glyph),
            default        => false,
        };
    }

    /** A filter that ignores marks only, as mark attachment uses to find a base or a ligature. */
    public function marksOnly(): self
    {
        return new self($this->gdef, self::IGNORE_MARKS);
    }

    private function ignoresMark(int $glyph): bool
    {
        if (0 !== ($this->flag & self::IGNORE_MARKS)) {
            return true;
        }
        if (0 !== ($this->flag & self::USE_MARK_FILTERING_SET)) {
            return ! ($this->gdef?->inMarkSet($this->markFilteringSet, $glyph) ?? false);
        }
        $type = $this->flag >> 8;

        return 0 !== $type && $type !== $this->gdef?->markAttachClass($glyph);
    }
}
