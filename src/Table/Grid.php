<?php

declare(strict_types=1);

namespace Php2Pdf\Table;

use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Text\Flow\StyleResolver;
use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\TextContext;
use Php2Pdf\Text\Textflow;
use Php2Pdf\Text\TextFormat;

use function array_key_exists;
use function count;
use function is_string;
use function spl_object_id;
use function sprintf;

/**
 * Rows of cells placed on the column grid, with spans resolved and every cell's style and
 * Textflow prepared.
 *
 * @internal
 */
final class Grid
{
    /** @var list<list<PlacedCell>> cells that start in each row */
    public array $rows = [];

    /** @var list<Row> */
    public readonly array $sources;

    /**
     * Text styles with a cell's alignment laid over them, by style and alignment: the style kept
     * with its result, so cells aligned alike share one style.
     *
     * @var array<string, array{0: TextStyle, 1: TextStyle}>
     */
    private array $aligned = [];

    /**
     * @param list<Row>    $rows
     * @param list<Column> $columns
     *
     * @throws IoException
     * @throws ParseException
     */
    public function __construct(
        array $rows,
        private readonly array $columns,
        private readonly ?CellStyle $tableStyle,
        TextStyle|string|null $tableText,
        TextContext $context,
    ) {
        $this->sources = $rows;
        $resolver      = StyleResolver::for($context);
        $base          = $resolver->resolve(null, $tableText);
        $occupied      = [];
        foreach ($rows as $r => $row) {
            $this->rows[$r] = [];
            $c              = 0;
            foreach ($row->cells as $cell) {
                while (array_key_exists("{$r}:{$c}", $occupied)) {
                    ++$c;
                }
                if (($c + $cell->colspan) > count($this->columns)) {
                    throw new InvalidArgumentException(sprintf(
                        'Row %d has more cells than the table has columns (%d).',
                        $r + 1,
                        count($this->columns),
                    ));
                }
                for ($dr = 0; $dr < $cell->rowspan; ++$dr) {
                    for ($dc = 0; $dc < $cell->colspan; ++$dc) {
                        $occupied[($r + $dr) . ':' . ($c + $dc)] = true;
                    }
                }
                $this->rows[$r][] = $this->place($context, $resolver, $base, $row, $cell, $r, $c);
                $c                += $cell->colspan;
            }
        }
    }

    public function count(): int
    {
        return count($this->rows);
    }

    /** Whether any text in the row remains to be placed. */
    public function hasTextLeft(int $row): bool
    {
        foreach ($this->rows[$row] ?? [] as $placed) {
            if (null !== $placed->flow && ! $placed->flow->isDone()) {
                return true;
            }
        }

        return false;
    }

    public function reset(): void
    {
        foreach ($this->rows as $cells) {
            foreach ($cells as $placed) {
                $placed->flow?->reset();
                $placed->drawn = false;
            }
        }
    }

    /** A text style with an alignment laid over it, made once for each style and alignment. */
    private function aligned(TextStyle $text, Alignment $alignment): TextStyle
    {
        $key = spl_object_id($text) . "|{$alignment->name}";
        $hit = $this->aligned[$key] ?? null;
        if (null !== $hit && $hit[0] === $text) {
            return $hit[1];
        }
        $aligned             = $text->merge(new TextStyle(alignment: $alignment));
        $this->aligned[$key] = [$text, $aligned];

        return $aligned;
    }

    /**
     * @throws IoException
     * @throws ParseException
     */
    private function place(
        TextContext $context,
        StyleResolver $resolver,
        TextStyle $base,
        Row $row,
        Cell $cell,
        int $r,
        int $c,
    ): PlacedCell {
        $column = $this->columns[$c] ?? null;
        $style  = ($this->tableStyle ?? new CellStyle())->merge($column?->style)
            ->merge($row->style)
            ->merge($cell->style);
        $text = $base;
        foreach ([$this->tableStyle, $column?->style, $row->style, $cell->style] as $layer) {
            if (null === $layer || null === $layer->text) {
                continue;
            }
            $text = $resolver->resolve($text, $layer->text);
        }
        $text = $resolver->resolve($text, $cell->text);
        if (null !== $style->alignment) {
            $text = $this->aligned($text, $style->alignment);
        }
        $content = $cell->content;
        $flow    = match (true) {
            $content instanceof Textflow => $content,
            is_string($content) && TextFormat::Markup === $cell->format => Textflow::fromMarkup(
                $context,
                $content,
                $text,
            ),
            is_string($content) => Textflow::fromText($context, $content, $text),
            default             => null,
        };

        return new PlacedCell($cell, $r, $c, $style, $text, $flow);
    }
}
