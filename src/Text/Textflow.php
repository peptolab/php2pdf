<?php

declare(strict_types=1);

namespace Php2Pdf\Text;

use Override;
use Php2Pdf\Canvas\Canvas;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Flow\FlowableInterface;
use Php2Pdf\Flow\FlowResult;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Text\Flow\BoxSplit;
use Php2Pdf\Text\Flow\BreakPlanner;
use Php2Pdf\Text\Flow\CopyFitter;
use Php2Pdf\Text\Flow\FlowCursor;
use Php2Pdf\Text\Flow\Itemiser;
use Php2Pdf\Text\Flow\ItemKind;
use Php2Pdf\Text\Flow\Layout;
use Php2Pdf\Text\Flow\LayoutResult;
use Php2Pdf\Text\Flow\LineBreaker;
use Php2Pdf\Text\Flow\LinePainter;
use Php2Pdf\Text\Flow\MarkupParser;
use Php2Pdf\Text\Flow\Paragraph;
use Php2Pdf\Text\Flow\ParagraphFactory;
use Php2Pdf\Text\Flow\ParagraphSource;
use Php2Pdf\Text\Flow\StyleResolver;
use Php2Pdf\Text\Flow\TextflowBuilder;
use Php2Pdf\Text\Hyphenation\Hyphenator;
use Php2Pdf\Text\Style\ParagraphFormat;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\Style\VerticalAlignment;

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
    /** Where the next fit starts. */
    private FlowCursor $cursor;

    /**
     * Breaks lines with the composer's plans kept from one fit to the next; made at the first
     * layout, so a flow never laid out does not carry one.
     */
    private ?LineBreaker $breaker = null;

    private ?FitInfo $last = null;

    /**
     * The last measure(): the cursor and width it was taken from, the first baseline it used, and
     * its layout, which the next fitInto() from the same place into a box as wide and tall enough
     * draws instead of laying the text out again. A table measures every cell before drawing it.
     *
     * @var array{0: FlowCursor, 1: float, 2: FirstBaseline, 3: LayoutResult}|null
     */
    private ?array $measured = null;

    /**
     * @param list<Paragraph> $paragraphs
     */
    private function __construct(
        private readonly array $paragraphs,
    ) {
        $this->cursor = FlowCursor::start();
    }

    /**
     * A builder for assembling a flow in code. Use Document::textflowBuilder().
     *
     * @internal
     *
     * @param array<string, Hyphenator> $hyphenators pattern hyphenators by language code
     *
     * @throws IoException
     * @throws ParseException
     */
    public static function build(
        TextContext $context,
        TextStyle|string|null $style = null,
        array $hyphenators = [],
    ): TextflowBuilder {
        $resolver = StyleResolver::for($context);

        return new TextflowBuilder($resolver, $resolver->named($style) ?? new TextStyle(), new Itemiser($hyphenators));
    }

    /**
     * Text with inline markup; see MarkupParser for the tags.
     *
     * @param array<string, Hyphenator> $hyphenators pattern hyphenators by language code
     *
     * @throws IoException
     * @throws ParseException
     */
    public static function fromMarkup(
        TextContext $context,
        string $markup,
        TextStyle|string|null $style = null,
        array $hyphenators = [],
        TextFormat $format = TextFormat::Markup,
    ): self {
        $resolver = StyleResolver::for($context);
        $base     = $resolver->named($style) ?? new TextStyle();
        $parser   = new MarkupParser($resolver, $base, $format);
        $parsed   = $parser->parse($markup);

        return self::fromParagraphs($resolver, $base, new Itemiser($hyphenators), $parsed);
    }

    /**
     * @internal
     *
     * @param list<ParagraphSource> $sources
     *
     * @throws IoException
     * @throws ParseException
     */
    public static function fromParagraphs(
        StyleResolver $resolver,
        TextStyle $base,
        Itemiser $itemiser,
        array $sources,
    ): self {
        return new self((new ParagraphFactory($resolver, $base, $itemiser))->paragraphs($sources));
    }

    /**
     * Text or markup read with an itemiser the caller shares between flows, as a table does
     * between its cells: Plain text as fromText() reads it, any other format as fromMarkup().
     *
     * @internal
     *
     * @throws IoException
     * @throws ParseException
     */
    public static function fromString(
        StyleResolver $resolver,
        TextStyle $base,
        Itemiser $itemiser,
        string $content,
        TextFormat $format,
    ): self {
        $plain  = TextFormat::Plain === $format;
        $parser = new MarkupParser($resolver, $base, $plain ? TextFormat::Markup : $format);
        $parsed = $parser->parse($plain ? self::plainMarkup($content) : $content);

        return self::fromParagraphs($resolver, $base, $itemiser, $parsed);
    }

    /**
     * Plain text: blank lines separate paragraphs, single line ends break lines, tabs and soft
     * hyphens are honoured.
     *
     * @param array<string, Hyphenator> $hyphenators pattern hyphenators by language code
     *
     * @throws IoException
     * @throws ParseException
     */
    public static function fromText(
        TextContext $context,
        string $text,
        TextStyle|string|null $style = null,
        array $hyphenators = [],
    ): self {
        return self::fromMarkup($context, self::plainMarkup($text), $style, $hyphenators);
    }

    /** Plain text as markup: escaped, blank lines made paragraph breaks and line ends <br>. */
    private static function plainMarkup(string $text): string
    {
        $escaped    = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
        $paragraphs = preg_split('/\R[ \t]*\R/', $escaped);
        $markup     = [];
        foreach (false === $paragraphs ? [$escaped] : $paragraphs as $paragraph) {
            $markup[] = preg_replace('/\R/', replacement: '<br>', subject: $paragraph) ?? $paragraph;
        }

        return implode("\n\n", $markup);
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
        $layout  = $this->measuredFor($box, $options) ?? (new Layout(
            $this->paragraphs,
            $this->cursor,
            $box->width,
            $box->height,
            $options,
            $this->breaker(),
        ))->run();
        $this->measured = null;
        if ($options->shrinkToFit && FlowResult::Done !== $layout->result) {
            [$layout, $scale] = CopyFitter::shrink($this->paragraphs, $this->cursor, $box, $options, $this->breaker());
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
        foreach ($layout->lines as $placed) {
            $line          = $placed->line;
            $point         = LinePainter::baselinePoint($canvas, $box, $line, $placed->baseline + $shift);
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
        $this->cursor = $layout->cursor;

        return $layout->result;
    }

    #[Override]
    public function isDone(): bool
    {
        return $this->cursor->paragraph >= count($this->paragraphs);
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
            $line = ParagraphFormat::of($paragraph->style)->leftIndent;
            foreach ($paragraph->items as $item) {
                if ($item->isForcedBreak()) {
                    $max  = max($max, $line);
                    $line = 0.0;
                    continue;
                }
                $line += $item->width;
            }
            $max = max($max, $line + ParagraphFormat::of($paragraph->style)->rightIndent);
        }

        return $max;
    }

    /**
     * The height the remaining text would take at the given width, without placing it.
     */
    public function measure(float $width, ?FlowOptions $options = null): float
    {
        $options        ??= new FlowOptions();
        $layout         = (new Layout(
            $this->paragraphs,
            $this->cursor,
            $width,
            PHP_FLOAT_MAX,
            $options,
            $this->breaker(),
        ))->run();
        $this->measured = [$this->cursor, $width, $options->firstBaseline, $layout];

        return $layout->height;
    }

    /** The width of the widest unbreakable piece of the remaining text. */
    public function minContentWidth(): float
    {
        $min = 0.0;
        foreach ($this->remainingParagraphs() as $paragraph) {
            $format = ParagraphFormat::of($paragraph->style);
            $indent = $format->leftIndent + $format->rightIndent;
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
            if ($i < $this->cursor->paragraph) {
                continue;
            }
            $remaining = $i === $this->cursor->paragraph
                ? BoxSplit::from($paragraph, $this->cursor->item, $this->cursor->carry)
                : $paragraph;
            $parts[] = $remaining->text();
        }

        return implode("\n\n", $parts);
    }

    /** Starts over from the first paragraph. */
    #[Override]
    public function reset(): void
    {
        $this->cursor   = FlowCursor::start();
        $this->last     = null;
        $this->measured = null;
    }

    private function breaker(): LineBreaker
    {
        return $this->breaker ??= new LineBreaker(new BreakPlanner());
    }

    /**
     * The layout of the last measure() when it holds for a fit into the box: taken from the
     * cursor the fit starts at, as wide as the box and with the same first baseline, all of the
     * text placed, and no taller than the box. Every line then fits the box as it did the
     * measure, and so does the first line of each paragraph a kept-with-next one waits for, so
     * laying out again would set the same lines. A layout stopped by a break is not kept: a
     * paragraph kept with one that opens with a break could move on in a shorter box.
     */
    private function measuredFor(Box $box, FlowOptions $options): ?LayoutResult
    {
        $measured = $this->measured;
        if (
            null === $measured
            || $measured[0] !== $this->cursor
            || $measured[1] !== $box->width
            || $measured[2] !== $options->firstBaseline
            || FlowResult::Done !== $measured[3]->result
            || $measured[3]->height > ($box->height + 1.0e-6)
        ) {
            return null;
        }

        return $measured[3];
    }

    /** @return list<Paragraph> the paragraphs not fully placed, the current one cut at the cursor */
    private function remainingParagraphs(): array
    {
        $out = [];
        foreach ($this->paragraphs as $i => $paragraph) {
            if ($i < $this->cursor->paragraph) {
                continue;
            }
            $out[] = $i === $this->cursor->paragraph
                ? BoxSplit::from($paragraph, $this->cursor->item, $this->cursor->carry)
                : $paragraph;
        }

        return $out;
    }
}
