<?php

declare(strict_types=1);

namespace PhpPdf\Text\Flow;

use PhpPdf\Text\Style\TextStyle;
use PhpPdf\Text\Textflow;

use function count;

/**
 * Builds a Textflow in code, so markup is optional.
 *
 * @api
 */
final class TextflowBuilder
{
    /** @var list<array{0: ?TextStyle, 1: list<array{0: string, 1: TextStyle}>, 2: list<array{0: int, 1: ItemKind}>}> */
    private array $paragraphs = [];

    /** @var list<array{0: string, 1: TextStyle}> */
    private array $spans = [];

    /** @var list<array{0: int, 1: ItemKind}> */
    private array $breaks = [];

    private ?TextStyle $paragraphStyle = null;
    private TextStyle $current;

    /** @internal */
    public function __construct(
        private readonly StyleResolver $resolver,
        private readonly TextStyle $base,
        private readonly Itemiser $itemiser,
    ) {
        $this->current = $resolver->resolve($base);
    }

    public function bold(string $text): self
    {
        return $this->text($text, new TextStyle(font: $this->current->bold ?? $this->current->font));
    }

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

    public function italic(string $text): self
    {
        return $this->text($text, new TextStyle(font: $this->current->italic ?? $this->current->font));
    }

    public function lineBreak(): self
    {
        $this->breaks[] = [count($this->spans), ItemKind::LineBreak];

        return $this;
    }

    public function pageBreak(): self
    {
        $this->breaks[] = [count($this->spans), ItemKind::PageBreak];

        return $this;
    }

    /** Starts a new paragraph, optionally with a paragraph style. */
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

    /** Adds text, optionally in a character style layered over the paragraph's. */
    public function text(string $text, TextStyle|string|null $style = null): self
    {
        $this->spans[] = [$text, null === $style ? $this->current : $this->resolver->resolve($this->current, $style)];

        return $this;
    }

    private function flush(): void
    {
        if ([] !== $this->spans || [] !== $this->breaks) {
            $this->paragraphs[] = [$this->paragraphStyle, $this->spans, $this->breaks];
        }
        $this->spans  = [];
        $this->breaks = [];
    }
}
