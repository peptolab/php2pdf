<?php

declare(strict_types=1);

namespace PhpPdf\Svg;

use PhpPdf\Canvas\Canvas;
use PhpPdf\Font\Font;
use PhpPdf\Font\TextOptions;
use PhpPdf\Geometry\Box;
use PhpPdf\Geometry\Matrix;

use function count;
use function is_string;
use function ltrim;
use function mb_strlen;
use function preg_match;
use function preg_replace;
use function rtrim;

/**
 * Lays out <text> and <tspan> content on the font engine: absolute and relative positions,
 * text anchors, letter spacing, and fill colours or gradients. Text is set as real glyphs, so
 * it stays searchable in the output.
 *
 * @internal
 */
final class TextRenderer
{
    public function __construct(
        private readonly Context $context,
        private readonly Painter $painter,
    ) {}

    /**
     * @param list<array{0: string, 1: Style, 2: Font, 3: float, 4: float, 5: float}> $chunk
     *
     * @return list<array{0: string, 1: Style, 2: Font, 3: float, 4: float, 5: float}>
     */
    private static function anchored(array $chunk, float $anchor, float $total): array
    {
        if (0.0 === $anchor) {
            return $chunk;
        }
        $shift = $anchor * $total;
        $out   = [];
        foreach ($chunk as $run) {
            $run[3] -= $shift;
            $out[]  = $run;
        }

        return $out;
    }

    private static function first(?string $list): ?string
    {
        if (null === $list) {
            return null;
        }
        $m = [];
        if (preg_match('/^\s*([^\s,]+)/', $list, $m) !== 1) {
            return null;
        }

        return $m[1] ?? null;
    }

    /**
     * Drops leading space of the first run and trailing space of the last, as xml:space default
     * handling does.
     *
     * @param list<array{0: string, 1: Style, 2: ?float, 3: ?float, 4: float, 5: float}> $runs
     *
     * @return list<array{0: string, 1: Style, 2: ?float, 3: ?float, 4: float, 5: float}>
     */
    private static function trim(array $runs): array
    {
        $count = count($runs);
        if (0 === $count) {
            return [];
        }
        $first = $runs[0] ?? null;
        $last  = $runs[$count - 1] ?? null;
        if (null === $first || null === $last) {
            return [];
        }
        $first[0]         = ltrim($first[0]);
        $runs[0]          = $first;
        $last             = $runs[$count - 1] ?? $last;
        $last[0]          = rtrim($last[0]);
        $runs[$count - 1] = $last;
        $out              = [];
        foreach ($runs as $run) {
            if ('' === $run[0]) {
                continue;
            }
            $out[] = $run;
        }

        return $out;
    }

    public function render(Canvas $canvas, Element $text, Style $style): void
    {
        $runs = [];
        $this->collect($text, $style, $runs, null, null);
        $runs = self::trim($runs);
        if ([] === $runs) {
            return;
        }
        $x      = $this->context->length(self::first($text->attribute('x')), 'x', $style) ?? 0.0;
        $y      = $this->context->length(self::first($text->attribute('y')), 'y', $style) ?? 0.0;
        $chunks = $this->chunks($runs, $x, $y);
        foreach ($chunks as $chunk) {
            $this->drawChunk($canvas, $chunk);
        }
    }

    /**
     * Groups positioned runs into chunks (each starting at an absolute position) with their
     * measured widths, so anchors can shift whole chunks.
     *
     * @param list<array{0: string, 1: Style, 2: ?float, 3: ?float, 4: float, 5: float}> $runs
     *
     * @return list<list<array{0: string, 1: Style, 2: Font, 3: float, 4: float, 5: float}>> text, style, font, x, y, width
     */
    private function chunks(array $runs, float $x, float $y): array
    {
        $chunks  = [];
        $current = [];
        $anchor  = 0.0;
        $start   = $x;
        foreach ($runs as [$text, $style, $absX, $absY, $dx, $dy]) {
            if ((null !== $absX || null !== $absY) && [] !== $current) {
                $chunks[] = self::anchored($current, $anchor, $x - $start);
                $current  = [];
            }
            if (null !== $absX) {
                $x = $absX;
            }
            if (null !== $absY) {
                $y = $absY;
            }
            if ([] === $current) {
                $anchor = $style->textAnchor();
                $start  = $x + $dx;
            }
            $x    += $dx;
            $y    += $dy;
            $font = $this->context->fonts->resolve($style->fontFamilies(), $style->isBold(), $style->isItalic());
            if (null === $font) {
                $this->context->warn('text without an available font');
                continue;
            }
            $size      = $style->fontSize();
            $width     = $font->width($text, $size) + ($style->letterSpacing() * mb_strlen($text, encoding: 'UTF-8'));
            $current[] = [$text, $style, $font, $x, $y, $width];
            $x         += $width;
        }
        if ([] !== $current) {
            $chunks[] = self::anchored($current, $anchor, $x - $start);
        }

        return $chunks;
    }

    /**
     * Collects the positioned text runs of an element in document order: its text nodes and
     * those of nested tspans. Pending absolute positions and offsets apply to the next run.
     *
     * @param list<array{0: string, 1: Style, 2: ?float, 3: ?float, 4: float, 5: float}> $runs
     */
    private function collect(Element $element, Style $style, array &$runs, ?float $absX, ?float $absY): void
    {
        $pending = [
            $absX,
            $absY,
            $this->context->length(self::first($element->attribute('dx')), 'x', $style) ?? 0.0,
            $this->context->length(self::first($element->attribute('dy')), 'y', $style) ?? 0.0,
        ];
        foreach ($element->children as $child) {
            if (is_string($child)) {
                $text = preg_replace('/\s+/u', replacement: ' ', subject: $child) ?? $child;
                if ('' === $text) {
                    continue;
                }
                $runs[]  = [$text, $style, $pending[0], $pending[1], $pending[2], $pending[3]];
                $pending = [null, null, 0.0, 0.0];
                continue;
            }
            if ('tspan' !== $child->name && 'a' !== $child->name) {
                $this->warnUnsupported($child);
                continue;
            }
            $childStyle = $style->derive($child, $this->context->sheet);
            if (! $childStyle->isDisplayed()) {
                continue;
            }
            $before = count($runs);
            $this->collect(
                $child,
                $childStyle,
                $runs,
                $this->context->length(self::first($child->attribute('x')), 'x', $childStyle, null),
                $this->context->length(self::first($child->attribute('y')), 'y', $childStyle, null),
            );
            $run = $runs[$before] ?? null;
            if (count($runs) > $before && null !== $run) {
                // Offsets pending on this element apply to the first run the child produced.
                $runs[$before] = [
                    $run[0],
                    $run[1],
                    $run[2] ?? $pending[0],
                    $run[3] ?? $pending[1],
                    $run[4] + $pending[2],
                    $run[5] + $pending[3],
                ];
                $pending = [null, null, 0.0, 0.0];
            }
        }
    }

    /** @param list<array{0: string, 1: Style, 2: Font, 3: float, 4: float, 5: float}> $chunk */
    private function drawChunk(Canvas $canvas, array $chunk): void
    {
        foreach ($chunk as [$text, $style, $font, $x, $y, $width]) {
            if (! $style->isVisible()) {
                continue;
            }
            $size   = $style->fontSize();
            $bounds = new Box(
                $x,
                $y - $font->ascender($size),
                $width,
                $font->ascender($size) - $font->descender($size),
            );
            $canvas->save();
            if (! $this->painter->fill($canvas, $style, $bounds)) {
                $canvas->restore();
                continue;
            }
            if ($style->fillOpacity() < 1.0) {
                $canvas->setOpacity($style->fillOpacity());
            }
            if (! $style->stroke()->isNone()) {
                $this->context->warn('stroked text');
            }
            $options = new TextOptions(charSpacing: $style->letterSpacing());
            $canvas->showRun(
                $font->shape($text, $options),
                $font,
                $size,
                new Matrix(1.0, 0.0, 0.0, -1.0, $x, $y),
                $options,
            );
            $canvas->restore();
        }
    }

    private function warnUnsupported(Element $child): void
    {
        if ('textPath' === $child->name || 'tref' === $child->name) {
            $this->context->warn("<{$child->name}>");
        }
    }
}
