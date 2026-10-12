<?php

declare(strict_types=1);

namespace Php2Pdf\Table;

use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Text\Flow\Itemiser;
use Php2Pdf\Text\Flow\StyleResolver;
use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\TextContext;
use Php2Pdf\Text\Textflow;
use Php2Pdf\Text\TextFormat;

use function array_keys;
use function count;
use function is_string;
use function max;
use function spl_object_id;
use function sprintf;

/**
 * Rows of cells placed on the column grid, with spans resolved up front and each row's cells,
 * with their styles and Textflows, prepared when the row is first needed and let go once it is
 * drawn, so a long table holds only the rows around the box being filled. Sizing auto columns
 * looks at every row first, and those rows stay built until they are drawn: building them twice
 * would cost more time than it saves memory.
 *
 * @internal
 */
final class Grid
{
    /**
     * How many rows one itemiser serves before release() lets it go with the words it keeps, so
     * a long table does not hold every word it has drawn.
     */
    private const int ITEMISER_ROWS = 256;

    /** @var list<Row> */
    public readonly array $sources;

    /** @var array<int, list<PlacedCell>> the cells that start in each row, for the rows built and kept */
    private array $built = [];

    /**
     * The columns of the cells of the rows where cells spanning down from rows above push cells
     * aside; every other row's cells sit side by side from the first column.
     *
     * @var array<int, list<int>>
     */
    private array $starts = [];

    /** @var array<int, int> the last row a cell starting in a row covers, for rows with cells spanning down */
    private array $reaches = [];

    private readonly StyleResolver $resolver;
    private readonly TextStyle $base;

    /** Itemises the cells' text, so the cells share the words and spaces they have in common. */
    private ?Itemiser $itemiser = null;

    /** The row release() last let the itemiser go at. */
    private int $itemiserFrom = 0;

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
        $this->sources  = $rows;
        $this->resolver = StyleResolver::for($context);
        $this->base     = $this->resolver->resolve(null, $tableText);
        $columnCount    = count($this->columns);
        // The last row each column is covered to by a cell spanning down into later rows.
        $covered = [];
        foreach ($rows as $r => $row) {
            $c      = 0;
            $starts = [];
            $pushed = false;
            $reach  = $r;
            foreach ($row->cells as $cell) {
                while (($covered[$c] ?? -1) >= $r) {
                    ++$c;
                    $pushed = true;
                }
                if (($c + $cell->colspan) > $columnCount) {
                    throw new InvalidArgumentException(sprintf(
                        'Row %d has more cells than the table has columns (%d).',
                        $r + 1,
                        $columnCount,
                    ));
                }
                $starts[] = $c;
                if ($cell->rowspan > 1) {
                    $last = $r + $cell->rowspan - 1;
                    for ($dc = 0; $dc < $cell->colspan; ++$dc) {
                        $covered[$c + $dc] = max($covered[$c + $dc] ?? -1, $last);
                    }
                    $reach = max($reach, $last);
                }
                $c += $cell->colspan;
            }
            if ($pushed) {
                $this->starts[$r] = $starts;
            }
            if ($reach > $r) {
                $this->reaches[$r] = $reach;
            }
        }
    }

    /**
     * The cells that start in a row, built the first time the row is asked for and kept until
     * release().
     *
     * @return list<PlacedCell>
     *
     * @throws IoException
     * @throws ParseException
     */
    public function cells(int $row): array
    {
        return $this->built[$row] ?? ($this->built[$row] = $this->build($row));
    }

    public function count(): int
    {
        return count($this->sources);
    }

    /** Whether any text in the row remains to be placed. */
    public function hasTextLeft(int $row): bool
    {
        foreach ($this->cells($row) as $placed) {
            if (null !== $placed->flow && ! $placed->flow->isDone()) {
                return true;
            }
        }

        return false;
    }

    /** The last row a cell starting in a row covers. */
    public function reach(int $row): int
    {
        return $this->reaches[$row] ?? $row;
    }

    /**
     * Lets go of the cells of the rows before $row, which are drawn, and every so many rows of the
     * itemiser; a row asked for again is built afresh.
     */
    public function release(int $row): void
    {
        if (($row - $this->itemiserFrom) >= self::ITEMISER_ROWS) {
            $this->itemiser     = null;
            $this->itemiserFrom = $row;
        }
        foreach (array_keys($this->built) as $r) {
            if ($r < $row) {
                unset($this->built[$r]);
            }
        }
    }

    public function reset(): void
    {
        foreach ($this->built as $cells) {
            foreach ($cells as $placed) {
                $placed->flow?->reset();
                $placed->drawn = false;
            }
        }
        // A row not built holds no state of its own, but a Textflow given as a cell's content does.
        foreach ($this->sources as $r => $row) {
            if (isset($this->built[$r])) {
                continue;
            }
            foreach ($row->cells as $cell) {
                if ($cell->content instanceof Textflow) {
                    $cell->content->reset();
                }
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
     * @return list<PlacedCell>
     *
     * @throws IoException
     * @throws ParseException
     */
    private function build(int $r): array
    {
        $row = $this->sources[$r] ?? null;
        if (null === $row) {
            return [];
        }
        $starts = $this->starts[$r] ?? null;
        $cells  = [];
        $c      = 0;
        foreach ($row->cells as $i => $cell) {
            $c       = $starts[$i] ?? $c;
            $cells[] = $this->place($row, $cell, $r, $c);
            $c       += $cell->colspan;
        }

        return $cells;
    }

    /**
     * @throws IoException
     * @throws ParseException
     */
    private function place(Row $row, Cell $cell, int $r, int $c): PlacedCell
    {
        $resolver = $this->resolver;
        $column   = $this->columns[$c] ?? null;
        $style    = ($this->tableStyle ?? new CellStyle())->merge($column?->style)
            ->merge($row->style)
            ->merge($cell->style);
        $text = $this->base;
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
            is_string($content) => Textflow::fromString(
                $resolver,
                $text,
                $this->itemiser ??= new Itemiser(),
                $content,
                // Markup cells read as fromMarkup() reads them, every other format as fromText().
                TextFormat::Markup === $cell->format ? TextFormat::Markup : TextFormat::Plain,
            ),
            default => null,
        };

        return new PlacedCell($cell, $r, $c, $style, $text, $flow);
    }
}
