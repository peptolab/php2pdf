<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use IntlChar;

use function array_map;
use function class_exists;
use function count;

/**
 * The text each glyph of a font stands for, for the font's ToUnicode map. A glyph has one entry,
 * the text it first stood for. Where a run's glyphs stand for other text (one glyph for two
 * characters, a glyph shown for an invisible character), the map cannot give that text back,
 * and the run says so, so its text can be written with it.
 *
 * @internal
 */
final class TextMapping
{
    /** @var array<int, list<int>> glyph id (or code) => the code points it stands for */
    private array $map = [];

    /**
     * Whether the text is one invisible character (a joiner, a bidi mark, a variation selector),
     * which is shown with a glyph that stands for other text.
     *
     * @param list<int> $text
     */
    private static function isIgnorable(array $text): bool
    {
        return (
            1 === count($text)
                && class_exists(IntlChar::class)
                && IntlChar::hasBinaryProperty($text[0], IntlChar::PROPERTY_DEFAULT_IGNORABLE_CODE_POINT)
        );
    }

    /**
     * Whether two texts read the same: a no-break space reads as a space.
     *
     * @param list<int> $a
     * @param list<int> $b
     */
    private static function same(array $a, array $b): bool
    {
        $space = static fn(int $codePoint): int => 0xA0 === $codePoint ? 0x20 : $codePoint;

        return array_map($space, $a) === array_map($space, $b);
    }

    /** @return array<int, list<int>> */
    public function map(): array
    {
        return $this->map;
    }

    /**
     * Maps the glyphs of a run not mapped yet, and whether the map gives back the run's text:
     * every glyph that stands for text has a glyph, and is mapped to that text.
     */
    public function record(GlyphRun $run): bool
    {
        $faithful = true;
        foreach ($run->glyphs as $glyph) {
            if ($glyph->codePoint < 0 && [] === $glyph->components) {
                continue;
            }
            $text = $glyph->codePoints();
            if (0 === $glyph->id || self::isIgnorable($text)) {
                $faithful = false;
                continue;
            }
            $mapped = $this->map[$glyph->id] ?? null;
            if (null === $mapped) {
                $this->map[$glyph->id] = $text;
                continue;
            }
            $faithful = $faithful && self::same($mapped, $text);
        }

        return $faithful;
    }
}
