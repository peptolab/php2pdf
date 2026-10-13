# Templates and PDF import

## Templates

A template is reusable artwork: a form XObject drawn once and placed any number of times.

```php
$header = $doc->template(Dimensions::mm(210, 30));            // its own canvas and origin
$header->canvas()->setFillColor(Cmyk::fromPercent(100, 44, 0, 0))->fillRect(0, 0, $header->width(), $header->height());
$header->canvas()->fitTextline('Php2Pdf', $bold, 24, new Box(80, 0, 300, $header->height()), TextlineOptions::at(Position::centerLeft()));

$page->place($header, Box::fromMm(0, 267, 210, 30), Fit::entire());
```

Text flows and tables go into a template's canvas, or any canvas, with `fitInto()`:
`$flow->fitInto($header->canvas(), $box)`. On a page, `$page->fitTextflow()` and
`$page->fitTable()` do the same.

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
them; macOS Preview ignores layers inside placed artwork and draws hidden ones. To hide them
everywhere, remove them on import:

```php
$layout = $doc->import('layout.ai', HiddenLayers::Remove);
```

Content marked with a layer that is off by default (directly, or through a membership dictionary
and its visibility policy) is cut from the page and from the forms it draws, and the layer is
left out of the layers panel. Visible layers stay switchable.

An imported page brings only its content unless its annotations are asked for. Flattening them
draws the annotations that print (highlights, stamps, the icons of notes, signatures, filled form
fields) into the page as their appearances, wherever and however it is placed:

```php
$scan = $doc->import('signed.pdf', annotations: ImportedAnnotations::Flatten);
$page->place($scan->page(1), $page->trimBox(), Fit::entire());
```

Annotations that do not print, hidden ones, and those in a layer removed with
`HiddenLayers::Remove` are left out; one in a layer that stays is drawn in it. Links to web
addresses come back as links where the page is placed directly on a page, within the placement's
clip; links within the source document are dropped, as its other pages are not in the output.
Under PDF/X, which keeps links out of the printed area, imported links are left out. Notes,
comments and form fields do not stay interactive.

The reader handles classic cross-reference tables, cross-reference streams and object streams,
chains of incremental updates, and rebuilds the cross-reference by scanning the file when
offsets are wrong. Flate (with PNG and TIFF predictors), LZW, ASCIIHex, ASCII85 and RunLength
streams are decoded; image codecs pass through.

### Encrypted files

Files encrypted with the standard security handler open as any other: RC4 (40 and 128-bit), and
AES-128 and AES-256, which need the openssl extension. A file without a user password, as most
are, opens without one; otherwise give its user or owner password:

```php
$file = PdfFile::open('protected.pdf', password: 'secret');
$doc->import($file)->page(1);
```

A file can also carry permissions that forbid copying its content or assembling its pages into
other documents, as importing it does. Such a file is refused with a `PermissionException` unless
it is opened with its owner password, or with `ignorePermissions: true`, which says you take
responsibility for honouring its author's permissions (PDFlib's `shrug` option works the same way).
A file that needs a password it was not given, or a wrong one, also throws `PermissionException`.

## Reading geometry

```php
$file = PdfFile::open('layout.pdf');
$file->pageCount(); $file->version(); $file->info();   // Title, Author, ... as UTF-8
$p = $file->page(1);
$p->mediaBox(); $p->cropBox(); $p->trimBox(); $p->bleedBox(); $p->artBox(); $p->rotation();
```

This lets layouts be driven from a template's own geometry, for example placing a text box
inside an imported page's trim box.
