# Cookbook

## An invoice, ported from PDFlib

The original script built the table cell by cell with option lists. The same invoice on Php2Pdf
defines styles once, declares the table, and lets `Document::flow()` paginate:

```php
use Php2Pdf\Color\Cmyk;
use Php2Pdf\Document\{Document, Page, PageSize};
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Table\{Border, Cell, CellStyle, Column, Padding, Table};
use Php2Pdf\Text\Style\{Alignment, Leading, TextStyle};

$doc  = new Document($path);
$doc->info()->setTitle("Invoice #{$invoice->number}");
$sans = $doc->fonts()->load('fonts/SourceSans3-Regular.otf');
$ink  = Cmyk::fromPercent(0, 0, 0, 100);

$styles = $doc->styles();
$styles->define('cell-title', new TextStyle(font: $sans, size: 7, leading: Leading::pt(11), color: $ink));
$styles->define('cell-text', new TextStyle(font: $sans, size: 10, leading: Leading::pt(11), color: $ink));
$styles->define('cell-text-sm', new TextStyle(size: 7), basedOn: 'cell-text');
$styles->define('amount', new TextStyle(alignment: Alignment::Right), basedOn: 'cell-text');
$styles->define('amount-title', new TextStyle(alignment: Alignment::Right), basedOn: 'cell-title');

$rule   = new CellStyle(borderBottom: new Border(0.25, $ink));
$margin = static fn (float $top, float $bottom): CellStyle => new CellStyle(padding: Padding::mm($top, 0, $bottom, 0));

$table = new Table([Column::mm(55), Column::auto(), Column::mm(30), Column::mm(20)], $margin(0, 3));
$table->addHeaderRow([Cell::text('CLIENT', 'cell-title'), Cell::text($customer->name, 'cell-text')->spanning(3)]);
$table->addHeaderRow([Cell::text('COMPANY', 'cell-title'), Cell::text($customer->address, 'cell-text')->spanning(3)]);
$table->addHeaderRow([
    Cell::text('REFERENCE', 'cell-title'),
    Cell::text('DESCRIPTION', 'cell-title')->spanning(2),
    Cell::text('PRICE', 'amount-title'),
], $margin(6, 3)->merge($rule));
foreach ($invoice->lines as $line) {
    $table->addRow([
        Cell::text($line->reference, 'cell-text-sm'),
        Cell::text($line->description, 'cell-text')->spanning(2),
        Cell::text(sprintf('$%0.2f', $line->amount), 'amount'),
    ], $margin(3, 3)->merge($rule));
}
$table->addRow([null, null, Cell::text('TAX 10%', 'cell-title'), Cell::text(sprintf('$%0.2f', $total / 11), 'amount')], $margin(6, 3)->merge($rule), group: 1);
$table->addRow([null, null, Cell::text('TOTAL PRICE', 'cell-title'), Cell::text(sprintf('$%0.2f', $total), 'amount')], $margin(3, 3)->merge($rule), group: 1);

$template = $doc->import('letterhead.pdf')->page(1);
$doc->flow($table, Box::fromMm(15, 48, 180, 176), function (Document $doc, int $n) use ($template, $invoice): Page {
    $page = $doc->addPage(PageSize::A4);
    $page->place($template, $page->mediaBox(), Fit::entire());
    $page->fitTextflow($doc->textflow("#{$invoice->number}", 'cell-text'), Box::fromMm(70, 234, 65, 5));
    $page->fitTextflow($doc->textflow('DATE', 'cell-title'), Box::fromMm(150, 234, 15, 5));
    $page->fitTextflow($doc->textflow($invoice->date->format('j F Y'), 'cell-text'), Box::fromMm(165, 234, 35, 5));

    return $page;
});
$doc->close();
```

The three header rows repeat on every page, the tax and total rows stay together, and the
address in the company cell keeps its line breaks. `test/Integration/InvoiceTest.php` is this
invoice as a test.

## A two-column brochure from a template and plain copy

```php
$layout = $doc->import('layout.ai')->page(1);
$flow   = $doc->textflow(file_get_contents('copy.txt'), 'body', TextFormat::Plain, ['en' => $en]);
$columns = [Box::fromMm(15, 20, 85, 250), Box::fromMm(110, 20, 85, 250)];
$doc->flow($flow, [$columns], function (Document $doc) use ($layout): Page {
    $page = $doc->addPage(PageSize::A4);
    $page->place($layout, $page->trimBox(), Fit::entire());

    return $page;
});
```

Each page's frames are filled in turn: the left column, then the right, then a new page. A
`<columnbreak>` in markup moves on to the next column, a `<pagebreak>` to the next page.

## Page X of Y

Finished pages are written as they go, so the total is not known while they are made. Place a
template on every page and draw the total into it when the document closes:

```php
$total = $doc->template(Dimensions::mm(10, 4));
$doc->onClose(fn (Document $doc) => $total->canvas()->drawText((string) $doc->pageCount(), $font, 8, 0, 1));

$doc->flow($flow, $box, function (Document $doc, int $n) use ($total, $font): Page {
    $page = $doc->addPage(PageSize::A4);
    $page->canvas()->drawText("Page {$n} of", $font, 8, 480, 20);
    $page->place($total, Box::fromMm(185, 6, 10, 4), Fit::noFit(Position::bottomLeft()));

    return $page;
});
```

`onClose()` callbacks run when `close()` is called, before the last page is finished, so they
can also draw on that page.

## A report with a repeating table and a figure

```php
$page = $doc->addPage(PageSize::A4);
$page->fitTextflow($doc->textflow('<p style="h1">Quarterly report</p>', null, TextFormat::Markup), Box::fromMm(20, 260, 170, 20));
$page->place($doc->svg('chart.svg'), Box::fromMm(20, 180, 170, 70), Fit::meet(Position::topLeft()));
$page->fitTable($table, Box::fromMm(20, 20, 170, 150));
if (! $table->isDone()) {
    $doc->flow($table, Box::fromMm(20, 20, 170, 250), fn (Document $doc): Page => $doc->addPage(PageSize::A4));
}
$doc->outline()->add('Quarterly report', 1);
```
