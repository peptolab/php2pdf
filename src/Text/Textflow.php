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
use Php2Pdf\Text\Flow\LineBreaker;
use Php2Pdf\Text\Flow\LinePainter;
use Php2Pdf\Text\Flow\MarkupParser;
use Php2Pdf\Text\Flow\Paragraph;
use Php2Pdf\Text\Flow\ParagraphFactory;
use Php2Pdf\Text\Flow\ParagraphSource;
use Php2Pdf\Text\Flow\StyleResolver;
use Php2Pdf\Text\Flow\TextflowBuilder;
use Php2Pdf\Text\Hyphenation\Hyphenator;
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

    /** Breaks lines with the composer's plans kept from one fit to the next. */
    private readonly LineBreaker $breaker;

    private ?FitInfo $last = null;

    /**
     * @param list<Paragraph> $paragraphs
     */
    private function __construct(
        private readonly array $paragraphs,
    ) {
        $this->cursor  = new FlowCursor();
        $this->breaker = new LineBreaker(new BreakPlanner());
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
        $resolver = new StyleResolver($context);

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
        $resolver = new StyleResolver($context);
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
        $escaped    = str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $text);
        $paragraphs = preg_split('/\R[ \t]*\R/', $escaped);
        $markup     = [];
        foreach (false === $paragraphs ? [$escaped] : $paragraphs as $paragraph) {
            $markup[] = preg_replace('/\R/', replacement: '<br>', subject: $paragraph) ?? $paragraph;
        }

        return self::fromMarkup($context, implode("\n\n", $markup), $style, $hyphenators);
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
        $layout  = (new Layout(
            $this->paragraphs,
            $this->cursor,
            $box->width,
            $box->height,
            $options,
            $this->breaker,
        ))->run();
        if ($options->shrinkToFit && FlowResult::Done !== $layout->result) {
            [$layout, $scale] = CopyFitter::shrink($this->paragraphs, $this->cursor, $box, $options, $this->breaker);
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
        return (new Layout(
            $this->paragraphs,
            $this->cursor,
            $width,
            PHP_FLOAT_MAX,
            $options ?? new FlowOptions(),
            $this->breaker,
        ))->run()
            ->height;
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
        $this->cursor = new FlowCursor();
        $this->last   = null;
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
