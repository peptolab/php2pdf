<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Text\Style\CharFormat;
use Php2Pdf\Text\Style\TextStyle;
use WeakMap;

use function max;

/**
 * A line's vertical metrics, from every font and artwork on it.
 *
 * @internal
 */
final class LineMetrics
{
    /** @var WeakMap<TextStyle, array{ascent: float, descent: float, capHeight: float, xHeight: float, lineHeight: float}>|null each paragraph style's own metrics */
    private static ?WeakMap $bases = null;

    /** @var WeakMap<TextStyle, array{0: float, 1: float, 2: float}>|null each box style's reach and line height; see extent() */
    private static ?WeakMap $extents = null;

    /**
     * The line's vertical metrics from every font and artwork on it, from what the line assembler
     * collected while it set the line: the distinct styles of its boxes, and its artwork. Artwork
     * rising above the text pushes the line down by the difference, as auto leading does.
     *
     * @param list<TextStyle>                        $styles
     * @param list<array{0: float, 1: InlineObject}> $inlines
     *
     * @return array{ascent: float, descent: float, capHeight: float, xHeight: float, lineHeight: float}
     */
    public static function ofStyles(array $styles, array $inlines, TextStyle $style): array
    {
        $metrics   = self::base($style);
        $artTop    = 0.0;
        $artBottom = 0.0;
        foreach ($inlines as [, $inline]) {
            $artTop    = max($artTop, $inline->height + $inline->raise);
            $artBottom = max($artBottom, -$inline->raise);
        }
        self::$extents ??= new WeakMap();
        foreach ($styles as $itemStyle) {
            /** @var array{0: float, 1: float, 2: float} $extent */
            $extent = self::$extents[$itemStyle] ?? self::extent($itemStyle);
            [$ascent, $descent, $lineHeight] = $extent;
            $metrics['ascent']     = max($metrics['ascent'], $ascent);
            $metrics['descent']    = max($metrics['descent'], $descent);
            $metrics['lineHeight'] = max($metrics['lineHeight'], $lineHeight);
        }

        $above                 = max(0.0, $artTop - $metrics['ascent']);
        $metrics['ascent']     += $above;
        $metrics['lineHeight'] += $above;
        $metrics['descent']    = max($metrics['descent'], $artBottom);

        return $metrics;
    }

    /**
     * The metrics of the paragraph's own style, which every line starts from: worked out once for
     * each style and kept while the style lives.
     *
     * @return array{ascent: float, descent: float, capHeight: float, xHeight: float, lineHeight: float}
     */
    private static function base(TextStyle $style): array
    {
        self::$bases ??= new WeakMap();
        $metrics     = self::$bases[$style] ?? null;
        if (null !== $metrics) {
            return $metrics;
        }
        $font   = StyleResolver::fontOf($style);
        $format = CharFormat::of($style);
        $size   = $format->size;

        return self::$bases[$style] = [
            'ascent'     => $font->ascender($size),
            'descent'    => -$font->descender($size),
            'capHeight'  => $font->capHeight($size),
            'xHeight'    => $font->xHeight($size),
            'lineHeight' => $format->lineHeight,
        ];
    }

    /**
     * How far text in a style reaches above and below the baseline, its rise included, and its
     * line height, kept while the style lives so ofStyles() works it out once for each style.
     *
     * @return array{0: float, 1: float, 2: float}
     */
    private static function extent(TextStyle $style): array
    {
        self::$extents ??= new WeakMap();
        $font          = StyleResolver::fontOf($style);
        $format        = CharFormat::of($style);
        $size          = $format->size;
        $rise          = $format->rise;

        return self::$extents[$style] = [
            $font->ascender($size) + $rise,
            -$font->descender($size) - $rise,
            $format->lineHeight,
        ];
    }
}
