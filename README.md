# PhpPdf

A pure-PHP library for precision PDF layout: embedded fonts, PDF/AI/SVG templates, images, and
text that flows through chained boxes across pages. It keeps the parts of PDFlib that matter for
design-led documents and leaves out the rest.

Requires PHP 8.3+ with `ext-zlib` and `ext-mbstring`. No other runtime dependencies.

## Status

Under construction. See the build plan for phases; the current state is:

| Phase | Area | State |
|------:|------|-------|
| 0 | Foundations: Mago QA, PHPUnit, CI, test harness | done |
| 1 | Writer core: objects, xref, streams, pages, boxes, metadata | done |
| 2 | Canvas and colour | done |
| 3 | Fonts | done |
| 4 | Images and fit engine | done |
| 5 | Textline and templates (v0.1) | done |
| 6 | PDF/AI import | done |
| 7 | Textflow | done |
| 8 | SVG: native converter and rsvg-convert adapter | done |
| 9 | Tables | done |
| 10 | Hardening: links, bookmarks, PDF/X-4, CFF fonts, 500-page performance | done |

## Documentation

The guides in [`docs/`](docs/README.md) cover installation, documents and pages, the canvas,
fonts and text, images, the fit engine, templates and PDF import, SVG, Textflow, tables, links
and PDF/X-4, a cookbook, limitations and development. Moving from PDFlib? See
[Coming from PDFlib](docs/coming-from-pdflib.md).

## Quick look

```php
use PhpPdf\Color\Rgb;
use PhpPdf\Document\Document;
use PhpPdf\Document\PageSize;
use PhpPdf\Geometry\Box;

$doc = new Document('out.pdf');
$doc->info()->setTitle('Hello');

$page = $doc->addPage(PageSize::A4);
$page->setTrimBox(Box::fromMm(10, 10, 190, 277));

$page->canvas()
    ->setFillColor(Rgb::fromHex('#0088b8'))
    ->roundedRect(72, 72, 200, 100, 12)
    ->fill();

$doc->close();
```

Units are points (1/72 in). `Unit::mm()`, `Unit::in()` and `Box::fromMm()` convert at the API
boundary. The origin is bottom-left as in PDF; pass `topDown: true` to `addPage()` (or set it in
`DocumentOptions`) for a top-left origin with y growing downwards, as in design tools.

## SVG

`$doc->svg('logo.svg')` converts an SVG to reusable artwork in pure PHP: paths and shapes,
transforms, viewBox and nested viewports, fills and strokes with opacity, linear and radial
gradients, patterns, clip paths, masks, group opacity, style sheets with simple selectors,
`<use>`/`<symbol>`, embedded PNG/JPEG images, and text set through the font engine so it stays
searchable. Filters and markers are skipped and reported by `NativeConverter::warnings()`. When
full fidelity matters and `rsvg-convert` is installed, pass `new RsvgConverter()` instead: the
SVG is converted by librsvg and imported through the PDF importer.

```php
$art = $doc->svg('artwork.svg', new NativeConverter(fallbackFont: $sans));
$page->place($art, Box::fromMm(20, 200, 80, 60), Fit::meet());
```

## Tables

Tables flow through boxes like Textflows: columns are fixed, percentages or sized from their
content; cells hold text, markup, Textflows or anything placeable, with column and row spans,
padding, borders and backgrounds; header rows repeat in every box; grouped rows stay together;
and a row too tall for the space left continues its text in the next box. `Document::flow()`
drains any flowable across pages, making each page through a closure.

```php
$table = new Table([Column::mm(55), Column::auto(), Column::mm(20, new CellStyle(alignment: Alignment::Right))], $base, 'body');
$table->addHeaderRow(['Reference', 'Project', 'Price'], new CellStyle(borderBottom: new Border(0.25)));
foreach ($entries as $entry) {
    $table->addRow([$entry->reference, $entry->name, sprintf('$%0.2f', $entry->price)]);
}
$doc->flow($table, Box::fromMm(15, 48, 180, 176), function (Document $doc, int $n) use ($template): Page {
    $page = $doc->addPage(PageSize::A4);
    $page->place($template, $page->mediaBox(), Fit::entire());

    return $page;
});
```

## Links, bookmarks and PDF/X-4

`$page->addLink($box, 'https://…')` or `->addLink($box, Destination::page(3))` makes an area a
link; `$doc->addDestination('chapter-2', Destination::top(5, 700))` names a place; and
`$doc->outline()->add('Chapter 2', 5)->add('Section', Destination::at(5, 0, 400))` builds the
bookmarks panel.

`new DocumentOptions(pdfx: PdfX::X4, rgbProfile: $srgb)` writes PDF/X-4: PDF 1.6, trim boxes on
every page, a `DefaultRGB` profile so RGB colours and images are device-independent, output
intent and conformance declarations in the Info dictionary and XMP, and transparency groups in
the output intent's colour space. The document refuses device colours the output intent cannot reproduce, imported pages that draw
in them, links inside the printed area, badly nested page boxes, and closing without `setOutputIntent()` or a title. The
structure follows ISO 15930-7; check the output with Acrobat Preflight or callas pdfToolbox for a
formal check.

## Design notes

- **Deterministic output.** Inject a `FixedClock` through `DocumentOptions` and identical input
  produces byte-identical PDFs, so tests compare against golden files.
- **Streaming.** Each page is written as soon as the next one starts. Fonts are subset and written
  when the document closes.
- **Validation.** Integration tests run `qpdf --check`, read geometry back with `pdfinfo`, and
  compare `pdftoppm` renders against baselines.

## Development

Install [Mago](https://mago.carthage.software/) once per machine, then:

```sh
composer install
composer check            # mago format --check, mago lint, mago analyze, unit tests
composer test-integration # needs qpdf and poppler-utils
```

QA configuration is inherited from [php-db/phpdb-qa-tools](https://github.com/php-db/phpdb-qa-tools).

## Licence

MIT.
