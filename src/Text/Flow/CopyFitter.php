<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Text\FlowOptions;
use Php2Pdf\Text\Style\TextStyle;

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
        $size    = $style->fontSize();
        $options = $style->textOptions();
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
                $item->inline->scaled($style->fontSize() / $item->style->fontSize()),
                $style,
            ),
            null !== $hyphen => new Item(
                $item->kind,
                $style,
                '',
                null,
                0.0,
                $hyphen,
                $font->width($hyphen, $size, $options) + Itemiser::kerningWidth($item->hyphenKerning, $style),
                $item->hyphenKerning,
            ),
            default => new Item($item->kind, $style),
        };

        return 0 === $item->level ? $scaled : $scaled->withLevel($item->level);
    }

    /**
     * @param list<Paragraph> $paragraphs
     *
     * @return list<Paragraph>
     */
    private static function scaledParagraphs(array $paragraphs, float $scale): array
    {
        $out = [];
        foreach ($paragraphs as $paragraph) {
            $style = $paragraph->style->scaled($scale);
            $items = [];
            foreach ($paragraph->items as $item) {
                $itemStyle = $item->style->scaled($scale);
                $items[]   = self::scaledItem($item, $itemStyle);
            }
            $out[] = new Paragraph($style, $items, $paragraph->level);
        }

        return $out;
    }
}
