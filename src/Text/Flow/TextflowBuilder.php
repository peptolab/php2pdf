<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Flow;

use Php2Pdf\Canvas\PlaceableInterface;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Navigation\Destination;
use Php2Pdf\Text\Style\ListMarker;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\Textflow;

use function count;

/**
 * Builds a Textflow in code, so markup is optional. A list is started with startList(), each
 * item with item(), and ended with endList(); a paragraph() inside an item continues it, and a
 * list started inside an item is nested in it.
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
    private readonly ListContext $lists;

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
        $this->lists   = new ListContext();
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

    /** Ends the innermost list, and its open item. */
    public function endList(): self
    {
        $this->flush();
        $this->lists->closeItem();
        $this->lists->close();

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

    /**
     * Starts the next item of the innermost list, in the given paragraph style, the list's, or the
     * flow's; outside a list it starts a paragraph.
     *
     * @throws IoException
     * @throws ParseException
     */
    public function item(TextStyle|string|null $style = null): self
    {
        $this->flush();
        $listStyle            = $this->lists->item();
        $this->paragraphStyle = $this->resolver->named($style) ?? $listStyle;
        $this->current        = $this->resolver->resolve($this->base, $this->paragraphStyle);

        return $this;
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

    /**
     * Starts a list, inside the open item if there is one: its items marked as given (bullets
     * by depth unless set), the first numbered $start, in a paragraph style of its own if given.
     *
     * @throws IoException
     * @throws ParseException
     */
    public function startList(?ListMarker $marker = null, int $start = 1, TextStyle|string|null $style = null): self
    {
        $this->flush();
        $this->lists->open($marker, $start, $this->resolver->named($style));

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
            $this->paragraphs[] = new ParagraphSource(
                $this->paragraphStyle,
                $this->spans,
                $this->breaks,
                $this->lists->position(),
            );
        }
        $this->spans  = [];
        $this->breaks = [];
    }
}
