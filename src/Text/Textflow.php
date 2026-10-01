<?php

declare(strict_types=1);

namespace PhpPdf\Text;

use Override;
use PhpPdf\Canvas\Canvas;
use PhpPdf\Canvas\FlowableInterface;
use PhpPdf\Document\Document;
use PhpPdf\Geometry\Box;
use PhpPdf\Text\Flow\Item;
use PhpPdf\Text\Flow\Itemiser;
use PhpPdf\Text\Flow\ItemKind;
use PhpPdf\Text\Flow\Layout;
use PhpPdf\Text\Flow\LinePainter;
use PhpPdf\Text\Flow\MarkupParser;
use PhpPdf\Text\Flow\Paragraph;
use PhpPdf\Text\Flow\StyleResolver;
use PhpPdf\Text\Flow\TextflowBuilder;
use PhpPdf\Text\Hyphenation\Hyphenator;
use PhpPdf\Text\Style\TextStyle;
use PhpPdf\Text\Style\VerticalAlignment;

use function array_slice;
use function count;
use function implode;
use function max;
use function preg_replace;
use function preg_split;
use function str_replace;

use const PHP_FLOAT_MAX;

/**
 * Styled text that flows through chained boxes. Each fit places as much as fits and remembers
 * where it stopped, so the caller decides where the rest goes: another column, the next page,
 * a different template.
 *
 * @api
 */
final class Textflow implements FlowableInterface
{
    private int      $paragraphIndex = 0;
    private int      $itemIndex      = 0;
    private ?FitInfo $last           = null;

    /**
     * @param list<Paragraph> $paragraphs
     */
    private function __construct(
        private readonly array $paragraphs,
    ) {}

    /**
     * A builder for assembling a flow in code.
     *
     * @param array<string, Hyphenator> $hyphenators pattern hyphenators by language code
     */
    public static function build(
        Document $document,
        TextStyle|string|null $style = null,
        array $hyphenators = [],
    ): TextflowBuilder {
        $resolver = new StyleResolver($document);

        return new TextflowBuilder($resolver, $resolver->named($style) ?? new TextStyle(), new Itemiser($hyphenators));
    }

    /**
     * Text with inline markup; see MarkupParser for the tags.
     *
     * @param array<string, Hyphenator> $hyphenators pattern hyphenators by language code
     */
    public static function fromMarkup(
        Document $document,
        string $markup,
        TextStyle|string|null $style = null,
        array $hyphenators = [],
        TextFormat $format = TextFormat::Markup,
    ): self {
        $resolver = new StyleResolver($document);
        $base     = $resolver->named($style) ?? new TextStyle();
        $parser   = new MarkupParser($resolver, $base, $format);
        $parsed   = $parser->parse($markup);

        return self::fromParagraphs($resolver, $base, new Itemiser($hyphenators), $parsed);
    }

    /**
     * @internal
     *
     * @param list<array{0: ?TextStyle, 1: list<array{0: string, 1: TextStyle}>, 2: list<array{0: int, 1: ItemKind|Item}>}> $parsed
     */
    public static function fromParagraphs(
        StyleResolver $resolver,
        TextStyle $base,
        Itemiser $itemiser,
        array $parsed,
    ): self {
        $paragraphs = [];
        foreach ($parsed as [$override, $spans, $breaks]) {
            $style   = $resolver->resolve($base, $override);
            $items   = [];
            $breakAt = [];
            foreach ($breaks as [$index, $kind]) {
                $breakAt[$index][] = $kind;
            }
            foreach ($spans as $i => $span) {
                foreach ($breakAt[$i] ?? [] as $kind) {
                    $items[] = $kind instanceof Item ? $kind : new Item($kind, $style);
                }
                foreach ($itemiser->itemise([$span]) as $item) {
                    $items[] = $item;
                }
            }
            foreach ($breakAt[count($spans)] ?? [] as $kind) {
                $items[] = $kind instanceof Item ? $kind : new Item($kind, $style);
            }
            $paragraphs[] = new Paragraph($style, $items);
        }

        return new self($paragraphs);
    }

    /**
     * Plain text: blank lines separate paragraphs, single line ends break lines, tabs and soft
     * hyphens are honoured.
     *
     * @param array<string, Hyphenator> $hyphenators pattern hyphenators by language code
     */
    public static function fromText(
        Document $document,
        string $text,
        TextStyle|string|null $style = null,
        array $hyphenators = [],
    ): self {
        $escaped    = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
        $paragraphs = preg_split('/\R[ \t]*\R/', $escaped);
        $markup     = [];
        foreach (false === $paragraphs ? [$escaped] : $paragraphs as $paragraph) {
            $markup[] = preg_replace('/\R/', replacement: '<br>', subject: $paragraph) ?? $paragraph;
        }

        return self::fromMarkup($document, implode("\n\n", $markup), $style, $hyphenators);
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

        return match (true) {
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
    }

    /**
     * Places as much of the remaining text as fits into the box.
     *
     * @internal use Canvas::fitTextflow()
     */
    #[Override]
    public function fitInto(Canvas $canvas, Box $box, ?FlowOptions $options = null): FlowResult
    {
        $options ??= new FlowOptions();
        $scale   = 1.0;
        $layout  = new Layout(
            $this->paragraphs,
            $this->paragraphIndex,
            $this->itemIndex,
            $box->width,
            $box->height,
            $options,
        );
        $layout->run();
        if ($options->shrinkToFit && FlowResult::Done !== $layout->result) {
            [$layout, $scale] = $this->shrink($box, $options);
        }

        $shift = 0.0;
        if (FlowResult::Done === $layout->result) {
            $shift = match ($options->verticalAlignment) {
                VerticalAlignment::Top    => 0.0,
                VerticalAlignment::Center => max(0.0, ($box->height - $layout->height) / 2.0),
                VerticalAlignment::Bottom => max(0.0, $box->height - $layout->height),
            };
        }
        LinePainter::draw($canvas, $box, $layout, $shift);

        $firstBaseline = null;
        $lastBaseline  = null;
        $textEnd       = null;
        foreach ($layout->lines as [$line, $baseline]) {
            $point         = LinePainter::baselinePoint($canvas, $box, $line, $baseline + $shift);
            $firstBaseline ??= $point;
            $lastBaseline  = $point;
            $textEnd       = [$point[0] + LinePainter::textWidth($line), $point[1]];
        }
        $this->last = new FitInfo(
            $layout->result,
            $layout->height,
            count($layout->lines),
            $firstBaseline,
            $lastBaseline,
            $scale,
            $textEnd,
        );
        $this->paragraphIndex = $layout->paragraphIndex;
        $this->itemIndex      = $layout->itemIndex;

        return $layout->result;
    }

    #[Override]
    public function isDone(): bool
    {
        return $this->paragraphIndex >= count($this->paragraphs);
    }

    /** Everything about the most recent fit, or null before the first. */
    public function lastFit(): ?FitInfo
    {
        return $this->last;
    }

    /**
     * The width of the longest line of the remaining text if nothing were wrapped: the natural
     * width of the widest paragraph.
     */
    public function maxContentWidth(): float
    {
        $max = 0.0;
        foreach ($this->remainingParagraphs() as $paragraph) {
            $line = $paragraph->style->leftIndent ?? 0.0;
            foreach ($paragraph->items as $item) {
                if ($item->isForcedBreak()) {
                    $max  = max($max, $line);
                    $line = 0.0;
                    continue;
                }
                $line += $item->width;
            }
            $max = max($max, $line + ($paragraph->style->rightIndent ?? 0.0));
        }

        return $max;
    }

    /**
     * The height the remaining text would take at the given width, without placing it.
     */
    public function measure(float $width, ?FlowOptions $options = null): float
    {
        $layout = new Layout(
            $this->paragraphs,
            $this->paragraphIndex,
            $this->itemIndex,
            $width,
            PHP_FLOAT_MAX,
            $options ?? new FlowOptions(),
        );

        return $layout->run()->height;
    }

    /** The width of the widest unbreakable piece of the remaining text. */
    public function minContentWidth(): float
    {
        $min = 0.0;
        foreach ($this->remainingParagraphs() as $paragraph) {
            $indent = ($paragraph->style->leftIndent ?? 0.0) + ($paragraph->style->rightIndent ?? 0.0);
            $word   = 0.0;
            foreach ($paragraph->items as $item) {
                if (ItemKind::Box === $item->kind || ItemKind::Inline === $item->kind) {
                    $word += $item->width;
                    continue;
                }
                $min  = max($min, $word + $indent);
                $word = 0.0;
            }
            $min = max($min, $word + $indent);
        }

        return $min;
    }

    /** The text not yet placed. */
    public function remainingText(): string
    {
        $parts = [];
        foreach ($this->paragraphs as $i => $paragraph) {
            if ($i < $this->paragraphIndex) {
                continue;
            }
            $items = $i === $this->paragraphIndex
                ? array_slice($paragraph->items, $this->itemIndex)
                : $paragraph->items;
            $remaining = new Paragraph($paragraph->style, $items);
            $parts[]   = $remaining->text();
        }

        return implode("\n\n", $parts);
    }

    /** Starts over from the first paragraph. */
    #[Override]
    public function reset(): void
    {
        $this->paragraphIndex = 0;
        $this->itemIndex      = 0;
        $this->last           = null;
    }

    private function layoutScaled(float $scale, Box $box, FlowOptions $options): Layout
    {
        $paragraphs = $this->scaledParagraphs($scale);

        $layout = new Layout(
            $paragraphs,
            $this->paragraphIndex,
            $this->itemIndex,
            $box->width,
            $box->height,
            $options,
        );

        return $layout->run();
    }

    /** @return list<Paragraph> the paragraphs not fully placed, the current one cut at the cursor */
    private function remainingParagraphs(): array
    {
        $out = [];
        foreach ($this->paragraphs as $i => $paragraph) {
            if ($i < $this->paragraphIndex) {
                continue;
            }
            $out[] =
                $i === $this->paragraphIndex && $this->itemIndex > 0
                    ? new Paragraph($paragraph->style, array_slice($paragraph->items, $this->itemIndex))
                    : $paragraph;
        }

        return $out;
    }

    /** @return list<Paragraph> */
    private function scaledParagraphs(float $scale): array
    {
        $out = [];
        foreach ($this->paragraphs as $paragraph) {
            $style = $paragraph->style->scaled($scale);
            $items = [];
            foreach ($paragraph->items as $item) {
                $itemStyle = $item->style->scaled($scale);
                $items[]   = self::scaledItem($item, $itemStyle);
            }
            $out[] = new Paragraph($style, $items);
        }

        return $out;
    }

    /**
     * Finds the largest scale within the shrink limit at which the remaining text fits.
     *
     * @return array{0: Layout, 1: float}
     */
    private function shrink(Box $box, FlowOptions $options): array
    {
        $lo   = $options->shrinkLimit;
        $hi   = 1.0;
        $best = null;
        for ($i = 0; $i < 8; ++$i) {
            $mid    = ($lo + $hi) / 2.0;
            $layout = $this->layoutScaled($mid, $box, $options);
            if (FlowResult::Done !== $layout->result) {
                $hi = $mid;
                continue;
            }
            $best = [$layout, $mid];
            $lo   = $mid;
        }

        return $best ?? [$this->layoutScaled($options->shrinkLimit, $box, $options), $options->shrinkLimit];
    }
}
