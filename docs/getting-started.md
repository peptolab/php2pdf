# Getting started

## Installation

```bash
composer require peptolab/php2pdf
```

Php2Pdf needs PHP 8.3 or later with `ext-zlib` (stream compression) and `ext-mbstring` (text).
`ext-imagick` or `ext-gd` are optional: they let images in formats other than JPEG and PNG be
placed by transcoding them.

## A first document

```php
use Php2Pdf\Color\Rgb;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Text\Style\Leading;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\TextFormat;

$doc = new Document('hello.pdf');
$doc->info()->setTitle('Hello');

$sans = $doc->fonts()->load('/path/to/Inter-Regular.ttf');
$doc->styles()->define('body', new TextStyle(font: $sans, size: 10, leading: Leading::pt(14)));

$page = $doc->addPage(PageSize::A4);
$page->canvas()
    ->setFillColor(Rgb::fromHex('#0088b8'))
    ->roundedRect(72, 700, 200, 60, 8)
    ->fill();

$flow = $doc->textflow("Hello, world.\n\nText flows through boxes.", 'body', TextFormat::Plain);
$page->fitTextflow($flow, Box::fromMm(25, 40, 160, 200));

$photo = $doc->image('photo.jpg');
$page->place($photo, Box::fromMm(25, 20, 60, 15), Fit::meet());

$doc->close();
```

`Document` writes pages as they finish, so finished pages do not stay in memory. Memory still
grows with the input you hand it: a `Table` keeps all its rows and a `Textflow` all its text until
they are fitted, so very long tables and single flows cost memory in proportion to their length.
Fonts are subset and written when `close()` is called.

## Units

Everything inside the library is in PDF points (1/72 inch). Convert at the API boundary:

```php
use Php2Pdf\Geometry\Box;
use Php2Pdf\Geometry\Unit;

Unit::mm(25.4);              // 72.0
Unit::in(1.0);               // 72.0
Unit::cm(2.54);              // 72.0
Unit::toMm(72.0);            // 25.4
Box::fromMm(10, 20, 100, 50); // x, y, width, height in millimetres
Box::fromIn(1, 1, 6, 4);
Dimensions::mm(210, 297);
```

A `Box` is `x, y, width, height`. `right()` is `x + width`; `far()` is `y + height`, which is the
top edge on a bottom-left page and the bottom edge on a top-down page.

## Coordinate origin

PDF's native origin is the bottom-left corner with y growing upwards, and that is the default.
Design tools use the top-left corner with y growing downwards. Choose per document or per page:

```php
$doc  = new Document('out.pdf', new DocumentOptions(topDown: true));
$page = $doc->addPage(PageSize::A4, topDown: true);
```

On a top-down page every coordinate you pass, including boxes for text, images and tables, is
measured from the top-left, and positive rotation angles turn clockwise as in design tools.
Templates have their own origin (`Document::template($size, Origin::TopLeft)`).

## Page sizes

`PageSize` provides A0 to A6, B4, B5, Letter, Legal, Tabloid and Executive; use
`PageSize::A4->landscape()` or any `Dimensions` for custom sizes.

## Validating output

The integration tests show the tools worth having in a pipeline:

- `qpdf --check file.pdf` verifies the file structure.
- `pdfinfo`, `pdftotext` and `pdftoppm` from poppler read geometry and text back and render
  pages to PNG for pixel comparison.
- Acrobat Preflight or callas pdfToolbox check PDF/X conformance (veraPDF validates PDF/A and
  PDF/UA, not PDF/X).
