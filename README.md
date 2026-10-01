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
| 2 | Canvas and colour | in progress |
| 3 | Fonts | pending |
| 4 | Images and fit engine | pending |
| 5 | Textline and templates (v0.1) | pending |
| 6 | PDF/AI import | pending |
| 7 | Textflow | pending |
| 8 | SVG | pending |
| 9 | Tables | pending |

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
