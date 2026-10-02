<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Font\TextDirection;
use Php2Pdf\Text\Style\CharFormat;
use Php2Pdf\Text\Style\TextStyle;
use WeakMap;

use function count;

/**
 * Items made from shaped text: boxes of words, glue of word spaces, glyphs cut from a run shaped
 * as a whole, and hyphenation points.
 *
 * @internal
 */
final class ItemFactory
{
    /** @var WeakMap<TextStyle, float>|null each style's word space width */
    private static ?WeakMap $spaces = null;

    /** A box of text at a bidi level, shaped right to left when the level is odd. */
    public static function box(string $text, TextStyle $style, int $level = 0): Item
    {
        $font    = StyleResolver::fontOf($style);
        $format  = CharFormat::of($style);
        $options = 1 === ($level & 1)
            ? $format->textOptions->withDirection(TextDirection::RightToLeft)
            : $format->textOptions;
        $run = $font->shape($text, $options);

        return new Item(
            ItemKind::Box,
            $style,
            $text,
            $run,
            $font->width($run, $format->size, $format->textOptions),
            level: $level,
        );
    }

    /**
     * An item for glyphs cut from a shaped run.
     *
     * @param list<Glyph> $glyphs
     */
    public static function cut(ItemKind $kind, TextStyle $style, string $text, array $glyphs): Item
    {
        $font   = StyleResolver::fontOf($style);
        $run    = new GlyphRun($glyphs);
        $format = CharFormat::of($style);

        return new Item($kind, $style, $text, $run, $font->width($run, $format->size, $format->textOptions));
    }

    public static function glue(TextStyle $style): Item
    {
        $font   = StyleResolver::fontOf($style);
        $format = CharFormat::of($style);
        $run    = $font->shape(' ', $format->textOptions);

        return new Item(ItemKind::Glue, $style, ' ', $run, $font->width($run, $format->size, $format->textOptions));
    }

    /** The width in points of a kerning change of $kerning thousandths of an em. */
    public static function kerningWidth(float $kerning, TextStyle $style): float
    {
        $format = CharFormat::of($style);

        return (($kerning / 1000.0) * $format->size * $format->horizontalScale) / 100.0;
    }

    /**
     * A hyphenation point: the hyphen, and how the kerning of the last glyph before it changes
     * when the line breaks there (from the next piece to the hyphen).
     *
     * @param list<Glyph> $before the glyphs of the piece before the break
     */
    public static function penalty(array $before, TextStyle $style): Item
    {
        $hyphen = ItemFactory::box('-', $style);
        $last   = $before[count($before) - 1] ?? null;
        $change = 0.0;
        if (null !== $last) {
            $pair   = StyleResolver::fontOf($style)->shape("{$last->text()}-", CharFormat::of($style)->textOptions);
            $first  = $pair->glyphs[count($pair->glyphs) - 2] ?? null;
            $change = (null === $first ? 0.0 : $first->kerning) - $last->kerning;
        }

        return new Item(
            ItemKind::Penalty,
            $style,
            '',
            null,
            0.0,
            $hyphen->run,
            $hyphen->width + ItemFactory::kerningWidth($change, $style),
            $change,
        );
    }

    /**
     * The width of a style's word space, without kerning: what its letter spacing is a percentage
     * of in justification. Worked out once for each style.
     */
    public static function spaceWidth(TextStyle $style): float
    {
        self::$spaces ??= new WeakMap();
        $width        = self::$spaces[$style] ?? null;
        if (null !== $width) {
            return $width;
        }
        $format  = CharFormat::of($style);
        $advance = 0;
        foreach (StyleResolver::fontOf($style)->shape(' ', $format->textOptions)->glyphs as $glyph) {
            $advance += $glyph->advance;
        }
        $width                = (($advance / 1000.0) * $format->size * $format->horizontalScale) / 100.0;
        self::$spaces[$style] = $width;

        return $width;
    }
}
