# Templates and PDF import

## Templates

A template is reusable artwork: a form XObject drawn once and placed any number of times.

```php
$header = $doc->template(Dimensions::mm(210, 30));            // its own canvas and origin
$header->canvas()->setFillColor(Cmyk::fromPercent(100, 44, 0, 0))->fillRect(0, 0, $header->width(), $header->height());
$header->canvas()->fitTextline('PhpPdf', $bold, 24, new Box(80, 0, 300, $header->height()), TextlineOptions::at(Position::centerLeft()));

$page->place($header, Box::fromMm(0, 267, 210, 30), Fit::entire());
```

Templates can be drawn on until the document closes. `asTransparencyGroup()` makes the form a
transparency group, so a group opacity or blend mode applied when placing it composites its
content as one object.

## Importing PDF and AI files

Pages of existing PDFs (and Illustrator `.ai` files saved PDF-compatible) become templates:

```php
$layout = $doc->import('layout.ai');                 // or Document::import(PdfFile::open(...))
$page->place($layout->page(1), $page->trimBox(), Fit::entire());
$page->place($layout->page(2, PageBox::Trim), Box::fromMm(20, 20, 80, 100), Fit::meet());
```

Each imported page is bounded by one of its boxes (`PageBox::Crop` by default) and honours its
`/Rotate`. Everything the page references is copied into the output; objects shared between
pages of the same file (fonts, images, colour spaces) are written once.

Layers (optional content groups) the placed pages use are merged into the document with the
source's default state, so guide or dieline layers switched off in Illustrator stay off, and
appear in a viewer's layers panel in the source's order. Acrobat, poppler and Ghostscript honour
them; macOS Preview ignores layers inside placed artwork and draws hidden ones.

The reader handles classic cross-reference tables, cross-reference streams and object streams,
chains of incremental updates, and rebuilds the cross-reference by scanning the file when
offsets are wrong. Flate (with PNG and TIFF predictors), LZW, ASCIIHex, ASCII85 and RunLength
streams are decoded; image codecs pass through. Encrypted files are rejected.

## Reading geometry

```php
$file = PdfFile::open('layout.pdf');
$file->pageCount(); $file->version(); $file->info();   // Title, Author, ... as UTF-8
$p = $file->page(1);
$p->mediaBox(); $p->cropBox(); $p->trimBox(); $p->bleedBox(); $p->artBox(); $p->rotation();
```

This lets layouts be driven from a template's own geometry, for example placing a text box
inside an imported page's trim box.
