<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Canvas\PlaceableInterface;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Navigation\Destination;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\Textflow;

use function count;

/**
 * Builds a Textflow in code, so markup is optional.
 *
 * @api
 */
final class TextflowBuilder
{
    /** @var list<ParagraphSource> */
    private array $paragraphs = [];

    /** @var list<array{0: string, 1: TextStyle}> */
    private array $spans = [];

    /** @var list<array{0: int, 1: ItemKind|Item}> */
    private array $breaks = [];

    private ?TextStyle $paragraphStyle = null;
    private TextStyle $current;

    /**
     * @internal
     *
     * @throws IoException
     * @throws ParseException
     */
    public function __construct(
        private readonly StyleResolver $resolver,
        private readonly TextStyle $base,
        private readonly Itemiser $itemiser,
    ) {
        $this->current = $resolver->resolve($base);
    }

    /**
     * @throws IoException
     * @throws ParseException
     */
    public function bold(string $text): self
    {
        return $this->text($text, new TextStyle(font: $this->current->bold ?? $this->current->font));
    }

    /**
     * @throws IoException
     * @throws ParseException
     */
    public function build(): Textflow
    {
        $this->flush();

        return Textflow::fromParagraphs($this->resolver, $this->base, $this->itemiser, $this->paragraphs);
    }

    public function columnBreak(): self
    {
        $this->breaks[] = [count($this->spans), ItemKind::ColumnBreak];

        return $this;
    }

    /**
     * Sets artwork in the text, its bottom on the baseline: sized by width or height in points
     * keeping its proportions, by both, or one em high when neither is given.
     */
    public function inline(
        PlaceableInterface $object,
        ?float $width = null,
        ?float $height = null,
        float $raise = 0.0,
    ): self {
        $this->breaks[] = [
            count($this->spans),
            Item::inline(
                InlineObject::sized($object, $width, $height, $raise, $this->current->fontSize()),
                $this->current,
            ),
        ];

        return $this;
    }

    /**
     * @throws IoException
     * @throws ParseException
     */
    public function italic(string $text): self
    {
        return $this->text($text, new TextStyle(font: $this->current->italic ?? $this->current->font));
    }

    public function lineBreak(): self
    {
        $this->breaks[] = [count($this->spans), ItemKind::LineBreak];

        return $this;
    }

    /**
     * Text that links to a URL or a destination, over each line's piece of it.
     *
     * @throws IoException
     * @throws ParseException
     */
    public function link(Destination|string $target, string $text, ?string $tooltip = null): self
    {
        return $this->text($text, new TextStyle(
            link: $target,
            linkTooltip: $tooltip,
        ));
    }

    /**
     * Text whose areas are reported under a name in FitInfo::$matchboxes.
     *
     * @throws IoException
     * @throws ParseException
     */
    public function match(string $name, string $text): self
    {
        return $this->text($text, new TextStyle(match: $name));
    }

    public function pageBreak(): self
    {
        $this->breaks[] = [count($this->spans), ItemKind::PageBreak];

        return $this;
    }

    /**
     * Starts a new paragraph, optionally with a paragraph style.
     *
     * @throws IoException
     * @throws ParseException
     */
    public function paragraph(TextStyle|string|null $style = null): self
    {
        $this->flush();
        $this->paragraphStyle = $this->resolver->named($style);
        $this->current        = $this->resolver->resolve($this->base, $this->paragraphStyle);

        return $this;
    }

    public function tab(): self
    {
        $this->breaks[] = [count($this->spans), ItemKind::Tab];

        return $this;
    }

    /**
     * Adds text, optionally in a character style layered over the paragraph's.
     *
     * @throws IoException
     * @throws ParseException
     */
    public function text(string $text, TextStyle|string|null $style = null): self
    {
        $this->spans[] = [$text, null === $style ? $this->current : $this->resolver->resolve($this->current, $style)];

        return $this;
    }

    private function flush(): void
    {
        if ([] !== $this->spans || [] !== $this->breaks) {
            $this->paragraphs[] = new ParagraphSource($this->paragraphStyle, $this->spans, $this->breaks);
        }
        $this->spans  = [];
        $this->breaks = [];
    }
}
