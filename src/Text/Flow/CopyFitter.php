<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Text\FlowOptions;
use Php2Pdf\Text\Style\CharFormat;
use Php2Pdf\Text\Style\TextStyle;
use WeakMap;

/**
 * Copy fitting: the largest scale, down to the shrink limit, at which the rest of a flow fits a
 * box, with its type, leading, spacing and indents scaled together.
 *
 * @internal
 */
final class CopyFitter
{
    /**
     * Finds the largest scale within the shrink limit at which the remaining text fits.
     *
     * @param list<Paragraph> $paragraphs
     *
     * @return array{0: LayoutResult, 1: float}
     */
    public static function shrink(
        array $paragraphs,
        FlowCursor $cursor,
        Box $box,
        FlowOptions $options,
        LineBreaker $breaker,
    ): array {
        $lo   = $options->shrinkLimit;
        $hi   = 1.0;
        $best = null;
        for ($i = 0; $i < 8; ++$i) {
            $mid    = ($lo + $hi) / 2.0;
            $layout = self::layout($paragraphs, $cursor, $mid, $box, $options, $breaker);
            if (FlowResult::Done !== $layout->result) {
                $hi = $mid;
                continue;
            }
            $best = [$layout, $mid];
            $lo   = $mid;
        }

        return $best ?? [
            self::layout($paragraphs, $cursor, $options->shrinkLimit, $box, $options, $breaker),
            $options->shrinkLimit,
        ];
    }

    /** @param list<Paragraph> $paragraphs */
    private static function layout(
        array $paragraphs,
        FlowCursor $cursor,
        float $scale,
        Box $box,
        FlowOptions $options,
        LineBreaker $breaker,
    ): LayoutResult {
        return (new Layout(
            self::scaledParagraphs($paragraphs, $scale),
            $cursor,
            $box->width,
            $box->height,
            $options,
            $breaker,
        ))->run();
    }

    /**
     * An item at a scaled style. Its shaped glyphs are kept, since kerning is in thousandths of an
     * em; only the widths are measured again.
     */
    private static function scaledItem(Item $item, TextStyle $style): Item
    {
        $font    = StyleResolver::fontOf($style);
        $format  = CharFormat::of($style);
        $size    = $format->size;
        $options = $format->textOptions;
        $hyphen  = $item->hyphenRun;

        $scaled = match (true) {
            null !== $item->run => new Item(
                $item->kind,
                $style,
                $item->text,
                $item->run,
                $font->width($item->run, $size, $options),
            ),
            null !== $item->inline => Item::inline(
                $item->inline->scaled($size / CharFormat::of($item->style)->size),
                $style,
            ),
            null !== $hyphen => new Item(
                $item->kind,
                $style,
                '',
                null,
                0.0,
                $hyphen,
                $font->width($hyphen, $size, $options) + ItemFactory::kerningWidth($item->hyphenKerning, $style),
                $item->hyphenKerning,
            ),
            default => new Item($item->kind, $style),
        };

        return 0 === $item->level ? $scaled : $scaled->withLevel($item->level);
    }

    /**
     * The paragraphs at a scale. Items of one style share one scaled style, so a line of them is
     * set as one run, as it is unscaled.
     *
     * @param list<Paragraph> $paragraphs
     *
     * @return list<Paragraph>
     */
    private static function scaledParagraphs(array $paragraphs, float $scale): array
    {
        $out    = [];
        $styles = new WeakMap();
        $scaled = static fn(TextStyle $style): TextStyle => $styles[$style] ??= $style->scaled($scale);
        foreach ($paragraphs as $paragraph) {
            $style = $scaled($paragraph->style);
            $items = [];
            foreach ($paragraph->items as $item) {
                $items[] = self::scaledItem($item, $scaled($item->style));
            }
            $out[] = new Paragraph($style, $items, $paragraph->level);
        }

        return $out;
    }
}
