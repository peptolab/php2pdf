# Tables

A `Table` flows through boxes exactly like a Textflow: each `fitTable()` places as many rows as
fit and returns `Done`, `BoxFull` or `BoxEmpty`.

```php
$table = $doc->table(
    [Column::mm(55), Column::auto(), Column::mm(30), Column::mm(20, new CellStyle(alignment: Alignment::Right))],
    new CellStyle(padding: Padding::mm(1, 0, 3, 0)),   // every cell's base style
    'body',                                           // every text cell's base text style
);
$table->addHeaderRow(['Reference', 'Project', '', 'Price'], new CellStyle(borderBottom: new Border(0.25)));
$table->addRow([$ref, Cell::text($name)->spanning(2), sprintf('$%0.2f', $price)]);
$table->addRow([null, null, Cell::text('TOTAL', 'cell-title'), Cell::text($total, 'amount')], group: 1);

$result = $page->fitTable($table, Box::fromMm(15, 48, 180, 176));
```

## Columns

`Column::pt(72)` or `Column::mm(55)` are fixed; `Column::percent(30)` is a share of the box
width; `Column::auto()` is sized from its content, at least its widest word and at most its
longest line, with the remaining width shared in proportion. A column can carry a `CellStyle`
its cells inherit. Columns wider than the box throw.

## Rows and cells

Cells are given as strings (plain text), `Textflow`s, anything placeable (images, templates,
SVG) or `null` for empty, or as `Cell` objects for control:

```php
Cell::text('Plain', 'style-name', $cellStyle);
Cell::markup('With <b>markup</b>');
Cell::of($image, Fit::meet());
Cell::text('Wide')->spanning(columns: 3, rows: 2);
Cell::text('Right')->styled(new CellStyle(alignment: Alignment::Right));
```

`addRow($cells, $style, $group, $minHeight)` and `addHeaderRow($cells, $style, $minHeight)`
accept a prepared `Row` as well. Header rows are drawn again at the top of every box the table
continues in (`TableOptions(repeatHeaders: false)` turns that off). Consecutive rows sharing a
group id are kept together across boxes when they can be.

### Footer rows

`addFooterRow($cells, $style, $minHeight)` rows are drawn straight after the last row placed in
every box, the last box included, and their height is reserved in each. To fill them per box,
for a subtotal carried forward, pass a callback; it is told which body rows the box holds and
returns one row for each footer row, or null to draw the rows as added:

```php
$table->addFooterRow(['Carried forward', '']);
$table->onFooter(function (FooterContext $box) use ($amounts): array {
    $sum = array_sum(array_slice($amounts, 0, $box->lastRow + 1));

    return [[$box->isLastBox ? 'Total' : 'Carried forward', number_format($sum, 2)]];
});
```

The callback's rows are laid out at the heights reserved for the footer rows added with
`addFooterRow()`, so give those the content or `$minHeight` the tallest footer needs.
`measure()` counts the footers once.

## Styles

`CellStyle` layers table < column < row < cell, later non-null values winning: `padding`
(`Padding::all()`, `symmetric()`, `mm()`), `background`, `border` for all sides or
`borderTop`/`Right`/`Bottom`/`Left` (`new Border($width, $color, $dash)`), `verticalAlignment`,
`alignment` (horizontal, applied to text and placeables), `firstBaseline` and `text` (a
`TextStyle` or name layered under the cell's own `text`).

Text in a cell starts with its ascender at the top of the padding. To match PDFlib's
`firstlinedist=leading`, put the first baseline one leading down for the whole table with
`TableOptions(firstBaseline: FirstBaseline::Leading)`, or for some cells with
`CellStyle(firstBaseline: ...)`. Rows are measured and drawn with the same setting.

## Splitting and results

A row that does not fit the space left at the top of a box continues its text in the next box
(`TableOptions(splitRows: false)` disables this; such a row then reports `BoxEmpty`). Row and
column spans are honoured; a spanning cell grows the last row it covers when it needs more
height.

`$table->lastFit()` returns a `TableFitInfo`: result, height used, rows placed, and the canvas
coordinates of every row edge (`rowEdges`, `bottom()`) and column edge (`columnEdges`), so you
can draw your own rules or continue below the table. `measure($width)` gives the height the
remaining rows need, laying out every one of them. To ask whether a table fits a height, use
`measureUpTo($width, $limit)`: it stops once the rows pass the limit and returns a `TableMeasure`
with the height so far, how many rows it measured and `fits()`, so asking whether a long table fits
the rest of a page costs a few rows rather than the whole table.

```php
if ($table->measureUpTo($box->width, $box->height)->fits()) {
    $page->fitTable($table, $box);
}
```

## Flowing across pages

`Document::flow()` drains any flowable (a Textflow or a Table) across as many pages as needed,
calling a closure to make each page:

```php
$pages = $doc->flow($table, [Box::fromMm(15, 48, 180, 176), Box::fromMm(15, 20, 180, 250)], function (Document $doc, int $n) use ($template): Page {
    $page = $doc->addPage(PageSize::A4);
    $page->place($template, $page->mediaBox(), Fit::entire());

    return $page;
});
```

With a list of boxes the first page uses the first box, later pages the following ones, and the
last box repeats. An entry may itself be a list of boxes, the page's frames (columns): content
fills them in turn and moves to a new page after the last, or at a `<pagebreak>`; a
`<columnbreak>` moves to the next frame. To share the first page with other content, fit the table on that page
yourself and call `flow()` for the rest when `isDone()` is false.
