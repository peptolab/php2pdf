# Cookbook

## An invoice, ported from PDFlib

The original script built the table cell by cell with option lists. The same invoice on PhpPdf
defines styles once, declares the table, and lets `Document::flow()` paginate:

```php
use PhpPdf\Color\Cmyk;
use PhpPdf\Document\{Document, Page, PageSize};
use PhpPdf\Fit\Fit;
use PhpPdf\Geometry\Box;
use PhpPdf\Table\{Border, Cell, CellStyle, Column, Padding, Table};
use PhpPdf\Text\Style\{Alignment, Leading, TextStyle};

$doc  = new Document($path);
$doc->info()->setTitle("Invoice #{$registration->reference}");
$sans = $doc->fonts()->load($config['fontname']);
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
$table->addHeaderRow([Cell::text('CLIENT', 'cell-title'), Cell::text($user->getFullName(), 'cell-text')->spanning(3)]);
$table->addHeaderRow([Cell::text('COMPANY', 'cell-title'), Cell::text($company, 'cell-text')->spanning(3)]);
$table->addHeaderRow([
    Cell::text('ENTRY REFERENCE', 'cell-title'),
    Cell::text('ENTRY PROJECT NAME', 'cell-title')->spanning(2),
    Cell::text('PRICE', 'amount-title'),
], $margin(6, 3)->merge($rule));
foreach ($registration->entry as $entry) {
    $table->addRow([
        Cell::text($entry->reference, 'cell-text-sm'),
        Cell::text($entry->project_name, 'cell-text')->spanning(2),
        Cell::text(sprintf('$%0.2f', $entry->total_price), 'amount'),
    ], $margin(3, 3)->merge($rule));
}
$table->addRow([null, null, Cell::text('GST 10%', 'cell-title'), Cell::text(sprintf('$%0.2f', $total / 11), 'amount')], $margin(6, 3)->merge($rule), group: 1);
$table->addRow([null, null, Cell::text('TOTAL PRICE', 'cell-title'), Cell::text(sprintf('$%0.2f', $total), 'amount')], $margin(3, 3)->merge($rule), group: 1);

$template = $doc->import($config['template'])->page(1);
$doc->flow($table, Box::fromMm(15, 48, 180, 176), function (Document $doc, int $n) use ($template, $registration): Page {
    $page = $doc->addPage(PageSize::A4);
    $page->place($template, $page->mediaBox(), Fit::entire());
    $page->fitTextflow($doc->textflow("#{$registration->reference}", 'cell-text'), Box::fromMm(70, 234, 65, 5));
    $page->fitTextflow($doc->textflow('DATE', 'cell-title'), Box::fromMm(150, 234, 15, 5));
    $page->fitTextflow($doc->textflow($date->format('j F Y'), 'cell-text'), Box::fromMm(165, 234, 35, 5));

    return $page;
});
$doc->close();
```

The three header rows repeat on every page, the GST and total rows stay together, and the
address in the company cell keeps its line breaks. `test/integration/InvoiceTest.php` is this
invoice as a test.

## A two-column brochure from a template and plain copy

```php
$layout = $doc->import('layout.ai')->page(1);
$flow   = $doc->textflow(file_get_contents('copy.txt'), 'body', TextFormat::Plain, ['en' => $en]);
$doc->flow($flow, [Box::fromMm(15, 20, 85, 250)], function (Document $doc) use ($layout): Page {
    $page = $doc->addPage(PageSize::A4);
    $page->place($layout, $page->trimBox(), Fit::entire());

    return $page;
});
```

For two columns on each page, drive the loop yourself: fit the left box, and when the result is
`BoxFull` fit the right box on the same page before adding the next.

## A report with a repeating table and a figure

```php
$page = $doc->addPage(PageSize::A4);
$page->fitTextflow($doc->textflow('<p style="h1">Quarterly report</p>'), Box::fromMm(20, 260, 170, 20));
$page->place($doc->svg('chart.svg'), Box::fromMm(20, 180, 170, 70), Fit::meet(Position::topLeft()));
$page->fitTable($table, Box::fromMm(20, 20, 170, 150));
if (! $table->isDone()) {
    $doc->flow($table, Box::fromMm(20, 20, 170, 250), fn (Document $doc): Page => $doc->addPage(PageSize::A4));
}
$doc->outline()->add('Quarterly report', 1);
```
