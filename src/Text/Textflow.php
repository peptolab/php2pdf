<?php

declare(strict_types=1);

namespace Php2Pdf\Text;

use Override;
use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Document\Document;
use Php2Pdf\Flow\FlowableInterface;
use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Font\Bidi\Bidi;
use Php2Pdf\Font\Bidi\BidiRuns;
use Php2Pdf\Font\TextDirection;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Text\Flow\BoxSplit;
use Php2Pdf\Text\Flow\Item;
use Php2Pdf\Text\Flow\Itemiser;
use Php2Pdf\Text\Flow\ItemKind;
use Php2Pdf\Text\Flow\Layout;
use Php2Pdf\Text\Flow\LinePainter;
use Php2Pdf\Text\Flow\MarkupParser;
use Php2Pdf\Text\Flow\Paragraph;
use Php2Pdf\Text\Flow\StyleResolver;
use Php2Pdf\Text\Flow\TextflowBuilder;
use Php2Pdf\Text\Hyphenation\Hyphenator;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\Style\VerticalAlignment;

use function array_slice;
use function count;
use function implode;
use function max;
use function mb_ord;
use function mb_str_split;
use function mb_strlen;
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
    private int $paragraphIndex = 0;
    private int $itemIndex      = 0;

    /** The characters of the item at the cursor already placed: a word split across lines. */
    private int      $carry = 0;
    private ?FitInfo $last  = null;

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
            foreach ($spans as $k => [$spanText, $spanStyle]) {
                $spans[$k] = [str_replace(["\r\n", "\r"], replace: "\n", subject: $spanText), $spanStyle];
            }
            [$levels, $level] = self::bidiLevels($spans, $style);
            $offset = 0;
            foreach ($spans as $i => $span) {
                foreach ($breakAt[$i] ?? [] as $kind) {
                    $items[] = $kind instanceof Item
                        ? $kind->withLevel($level)
                        : new Item($kind, $style, level: $level);
                }
                $length = mb_strlen($span[0], encoding: 'UTF-8');
                foreach ($itemiser->itemise([$span], array_slice($levels, $offset, $length)) as $item) {
                    $items[] = $item;
                }
                $offset += $length;
            }
            foreach ($breakAt[count($spans)] ?? [] as $kind) {
                $items[] = $kind instanceof Item ? $kind->withLevel($level) : new Item($kind, $style, level: $level);
            }
            $paragraphs[] = new Paragraph($style, $items, $level);
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
     * The bidi levels of a paragraph's characters and the paragraph level, when its text has
     * right-to-left characters or its style makes it right to left; none and 0 otherwise.
     *
     * @param list<array{0: string, 1: TextStyle}> $spans
     *
     * @return array{0: list<int>, 1: int}
     */
    private static function bidiLevels(array $spans, TextStyle $style): array
    {
        $text = '';
        foreach ($spans as [$spanText]) {
            $text .= $spanText;
        }
        $direction = $style->direction ?? TextDirection::Auto;
        if (! BidiRuns::needed($text, $direction)) {
            return [[], 0];
        }
        $codePoints = [];
        foreach (mb_str_split($text, length: 1, encoding: 'UTF-8') as $char) {
            $codePoints[] = (int) mb_ord($char, encoding: 'UTF-8');
        }
        $paragraph = Bidi::paragraph($codePoints, $direction->level());

        return [$paragraph->levels(), $paragraph->level];
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
     * Places as much of the remaining text as fits into a box on any canvas (a page's, a
     * template's). Page::fitTextflow() does the same on a page.
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
            $this->carry,
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
        [$links, $matchboxes] = LinePainter::markedAreas($canvas, $box, $layout, $shift);
        foreach ($links as [$area, $target, $tooltip]) {
            $canvas->linkArea($area, $target, $tooltip);
        }

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
            $matchboxes,
        );
        $this->paragraphIndex = $layout->paragraphIndex;
        $this->itemIndex      = $layout->itemIndex;
        $this->carry          = $layout->carry;

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
            $this->carry,
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
            $remaining = $i === $this->paragraphIndex
                ? BoxSplit::from($paragraph, $this->itemIndex, $this->carry)
                : $paragraph;
            $parts[] = $remaining->text();
        }

        return implode("\n\n", $parts);
    }

    /** Starts over from the first paragraph. */
    #[Override]
    public function reset(): void
    {
        $this->paragraphIndex = 0;
        $this->itemIndex      = 0;
        $this->carry          = 0;
        $this->last           = null;
    }

    private function layoutScaled(float $scale, Box $box, FlowOptions $options): Layout
    {
        $paragraphs = $this->scaledParagraphs($scale);

        $layout = new Layout(
            $paragraphs,
            $this->paragraphIndex,
            $this->itemIndex,
            $this->carry,
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
            $out[] = $i === $this->paragraphIndex
                ? BoxSplit::from($paragraph, $this->itemIndex, $this->carry)
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
            $out[] = new Paragraph($style, $items, $paragraph->level);
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
