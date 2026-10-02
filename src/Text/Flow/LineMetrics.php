<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Text\Style\TextStyle;

use function max;

/**
 * A line's vertical metrics, from every font and artwork on it.
 *
 * @internal
 */
final class LineMetrics
{
    /**
     * The line's vertical metrics from every font and artwork on it. Artwork rising above the text
     * pushes the line down by the difference, as auto leading does.
     *
     * @param list<Item> $items
     *
     * @return array{ascent: float, descent: float, capHeight: float, xHeight: float, lineHeight: float}
     */
    public static function of(array $items, TextStyle $style): array
    {
        $font    = StyleResolver::fontOf($style);
        $size    = $style->fontSize();
        $metrics = [
            'ascent'     => $font->ascender($size),
            'descent'    => -$font->descender($size),
            'capHeight'  => $font->capHeight($size),
            'xHeight'    => $font->xHeight($size),
            'lineHeight' => $style->lineHeight(),
        ];
        $measured  = null;
        $artTop    = 0.0;
        $artBottom = 0.0;
        foreach ($items as $item) {
            if (null !== $item->inline) {
                $artTop    = max($artTop, $item->inline->height + $item->inline->raise);
                $artBottom = max($artBottom, -$item->inline->raise);
                continue;
            }
            if (ItemKind::Box !== $item->kind || $item->style === $measured) {
                continue;
            }
            $measured              = $item->style;
            $f                     = StyleResolver::fontOf($item->style);
            $s                     = $item->style->fontSize();
            $rise                  = $item->style->rise ?? 0.0;
            $metrics['ascent']     = max($metrics['ascent'], $f->ascender($s) + $rise);
            $metrics['descent']    = max($metrics['descent'], -$f->descender($s) - $rise);
            $metrics['lineHeight'] = max($metrics['lineHeight'], $item->style->lineHeight());
        }

        $above                 = max(0.0, $artTop - $metrics['ascent']);
        $metrics['ascent']     += $above;
        $metrics['lineHeight'] += $above;
        $metrics['descent']    = max($metrics['descent'], $artBottom);

        return $metrics;
    }
}
