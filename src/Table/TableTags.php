<?php

declare(strict_types=1);

namespace Php2Pdf\Table;

use Php2Pdf\Canvas\Tagger;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Structure\StructureElement;
use Php2Pdf\Structure\StructureRole;

/**
 * The elements a table is tagged as on a tagged page: the table, a row for each row drawn and a
 * header or data cell for each of its cells, with their spans and, for header cells, the column
 * they head. All are kept from one box to the next, so a row broken across boxes stays one row.
 * Header rows are tagged in the first box only; repeated in the boxes after, they are artifacts.
 *
 * @internal
 */
final class TableTags
{
    private ?StructureElement $table = null;

    /** @var array<string, StructureElement> the rows drawn, by part and index */
    private array $rows = [];

    /** @var array<string, StructureElement> the cells drawn, by part, row and column */
    private array $cells = [];

    /** Whether the header rows have been drawn in an earlier box. */
    private bool $headed = false;

    /** A cell's spans, and for a header cell the column it heads, as table attributes. */
    private static function attributes(PlacedCell $placed, Part $part): ?Dictionary
    {
        $header  = Part::Header === $part;
        $rows    = $placed->cell->rowspan;
        $columns = $placed->cell->colspan;
        if (! $header && 1 === $rows && 1 === $columns) {
            return null;
        }

        return Dictionary::of([
            'O'       => new Name('Table'),
            'Scope'   => $header ? new Name('Column') : null,
            'RowSpan' => 1 === $rows ? null : $rows,
            'ColSpan' => 1 === $columns ? null : $columns,
        ]);
    }

    /**
     * The element a cell's content is drawn as, or null for an artifact: a repeated header's, or
     * a cell of no part, which a fit does not tag.
     */
    public function cell(Tagger $tagger, ?Part $part, PlacedCell $placed): ?StructureElement
    {
        if (null === $part || (Part::Header === $part && $this->headed)) {
            return null;
        }
        $key  = "{$part->name}:{$placed->row}:{$placed->column}";
        $cell = $this->cells[$key] ?? null;
        if (null !== $cell) {
            return $cell;
        }
        $rowKey              = "{$part->name}:{$placed->row}";
        $this->rows[$rowKey] ??= $tagger->element(StructureRole::TableRow, parent: $this->table);
        $row                 = $this->rows[$rowKey];
        $cell                = $tagger->element(
            Part::Header === $part ? StructureRole::TableHeader : StructureRole::TableData,
            attributes: self::attributes($placed, $part),
            parent: $row,
        );
        $cell->attach();

        return $this->cells[$key] = $cell;
    }

    /** Starts drawing a box of the table, made the first time under what is being drawn. */
    public function enter(Tagger $tagger): void
    {
        if (null !== $this->table) {
            $this->headed = true;

            return;
        }
        $this->table = $tagger->element(StructureRole::Table);
    }
}
