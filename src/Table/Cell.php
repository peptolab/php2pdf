<?php

declare(strict_types=1);

namespace PhpPdf\Table;

use PhpPdf\Canvas\PlaceableInterface;
use PhpPdf\Exception\InvalidArgumentException;
use PhpPdf\Fit\Fit;
use PhpPdf\Text\Style\TextStyle;
use PhpPdf\Text\Textflow;
use PhpPdf\Text\TextFormat;

/**
 * A table cell: text (plain or marked up), a prepared Textflow, or anything placeable (an
 * image, a template, an imported page, SVG artwork), spanning one or more columns and rows.
 *
 * @api
 */
final readonly class Cell
{
    /**
     * @param TextStyle|string|null $text   the text style for string content, layered over the cell style's
     * @param Fit|null              $fit    how placeable content is fitted into the cell; meet, aligned per the
     *                                      cell style, by default
     */
    public function __construct(
        public string|Textflow|PlaceableInterface|null $content = null,
        public int $colspan = 1,
        public int $rowspan = 1,
        public ?CellStyle $style = null,
        public TextStyle|string|null $text = null,
        public TextFormat $format = TextFormat::Plain,
        public ?Fit $fit = null,
    ) {
        if ($colspan < 1 || $rowspan < 1) {
            throw new InvalidArgumentException('Cells span at least one column and one row.');
        }
    }

    public static function empty(?CellStyle $style = null): self
    {
        return new self(null, 1, 1, $style);
    }

    /** Text with inline markup (see MarkupParser). */
    public static function markup(string $markup, TextStyle|string|null $text = null, ?CellStyle $style = null): self
    {
        return new self($markup, 1, 1, $style, $text, TextFormat::Markup);
    }

    public static function of(PlaceableInterface $content, ?Fit $fit = null, ?CellStyle $style = null): self
    {
        return new self($content, 1, 1, $style, null, TextFormat::Plain, $fit);
    }

    public static function text(string $text, TextStyle|string|null $style = null, ?CellStyle $cellStyle = null): self
    {
        return new self($text, 1, 1, $cellStyle, $style);
    }

    public function hasText(): bool
    {
        return null !== $this->content && ! $this->content instanceof PlaceableInterface;
    }

    /** The same cell spanning the given columns and rows. */
    public function spanning(int $columns, int $rows = 1): self
    {
        return new self($this->content, $columns, $rows, $this->style, $this->text, $this->format, $this->fit);
    }

    /** The same cell with a style layered on top. */
    public function styled(CellStyle $style): self
    {
        return new self(
            $this->content,
            $this->colspan,
            $this->rowspan,
            null === $this->style ? $style : $this->style->merge($style),
            $this->text,
            $this->format,
            $this->fit,
        );
    }
}
