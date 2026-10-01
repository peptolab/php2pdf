# Documents and pages

## Creating a document

```php
use PhpPdf\Core\FixedClock;
use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Core\Output\StreamOutput;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;

$doc = new Document('out.pdf');                       // a file path
$doc = new Document(new StreamOutput($resource));     // any writable stream, e.g. php://output
$doc = new Document($memory = new MemoryOutput());    // in memory; $memory->contents() after close()
```

`DocumentOptions` fixes behaviour for the document's lifetime:

| Option | Default | Meaning |
|--------|---------|---------|
| `version` | `'1.7'` | The PDF version in the header |
| `compress`, `compressionLevel` | `true`, `6` | Flate-compress content streams |
| `clock` | `SystemClock` | Supplies creation dates; inject `FixedClock` for reproducible output |
| `producer` | `'PhpPdf'` | Written to the Info dictionary and XMP |
| `xmp` | `true` | Write an XMP metadata packet |
| `topDown` | `false` | Default coordinate origin of pages |
| `pdfx` | `null` | Write to a PDF/X level (see [navigation-and-pdfx.md](navigation-and-pdfx.md)) |
| `rgbProfile` | `null` | An ICC profile RGB colours are interpreted in |
| `glyphCheck` | `Warn` (`Error` under PDF/X) | What a character missing from its font does (see [fonts-and-text.md](fonts-and-text.md)) |
| `limits` | `Limits::default()` | Resource limits for the PDFs, images and SVG the document reads |

`Limits` caps what reading a hostile or broken file can cost: `maxDecodedBytes` (256 MiB per
decompressed stream), `maxImagePixels` (100 million), `maxPages` (100,000 per imported PDF),
`maxXrefEntries` (10 million), `maxNesting` (256 levels of SVG elements) and `maxSvgUses`
(10,000 `<use>` expansions). A file over a limit fails with a `PdfException`. The static
constructors (`PdfFile::fromData()`, `SvgFile::fromData()`, `ImageReader::read()`) take a
`Limits` too; lower the limits for files from untrusted sources.

## Handling untrusted files

The library parses these inputs, and any of them may come from a user upload:

- **PDF and AI files** (`import()`, `PdfFile`): the cross-reference, object and page-tree
  parsers and the stream filters, bounded by `maxDecodedBytes`, `maxPages` and
  `maxXrefEntries`, with cycles in the page tree detected;
- **images** (`image()`, `imageFromData()`): the PNG decoder and the header readers of other
  formats, bounded by `maxImagePixels` before anything is decoded; formats other than JPEG and
  PNG go to Imagick or GD, which have their own limits;
- **SVG** (`svg()`, `SvgFile`): the XML parser and renderer, bounded by `maxNesting`,
  `maxSvgUses` and, for `.svgz`, `maxDecodedBytes`. `<image>` elements may read files: a path is
  resolved against the SVG's directory, or the working directory for SVG read from a string, and
  absolute paths are followed. Do not render untrusted SVG in a process that can read files it
  should not disclose;
- **fonts** (`fonts()->load()`): the OpenType parser. Fonts are not covered by `Limits`; load
  only fonts you trust.

The limits stop a small file from asking for unbounded work, but PHP cannot recover from running
out of memory or time: those are fatal errors, not exceptions, and no `catch` sees them. For
files from untrusted sources:

- generate the PDF in a separate worker process (a queue job or a CLI subprocess), not in the
  web request that accepted the upload;
- give that process its own `memory_limit` and a time limit (`set_time_limit()` or the
  supervisor's timeout), sized for your largest legitimate document;
- lower `Limits` to what your documents need;
- treat a crashed worker as a rejected file, and write to a temporary path (`Document` already
  renames the file into place only when `close()` succeeds).

## Deterministic output

With a `FixedClock` identical input produces byte-identical files: object numbers, the
document ID (an MD5 over the file's content) and XMP identifiers are all derived
deterministically. Golden-file tests compare whole PDFs this way.

## Pages

```php
$page = $doc->addPage(PageSize::A4);
$page = $doc->addPage(Dimensions::mm(148, 210), topDown: true);
```

Adding a page finishes and writes the previous one, so draw on a page before adding the next.
`Document::currentPage()` returns the open page; `pageCount()` the number of pages so far.

Every page carries the five PDF boxes. The media box is the page size; the others default to it:

```php
$page->setTrimBox(Box::fromMm(10, 10, 190, 277));
$page->setBleedBox(Box::fromMm(7, 7, 196, 283));
$page->setCropBox(...); $page->setArtBox(...);
$page->setRotation(90);            // display rotation, multiples of 90
$page->trimBox(); $page->mediaBox(); $page->width(); $page->height();
```

Boxes are given in the page's coordinate mode; `toPdfBox()` converts a top-down box to PDF
coordinates when you need to talk to the file directly.

## Metadata

```php
$doc->info()->setTitle('Brochure')->setAuthor('Studio')->setSubject('...')->setKeywords('a, b')->setCreator('My app');
$doc->addXmpDescription('<rdf:Description ...>...</rdf:Description>');  // extra RDF for the XMP packet
$doc->setOutputIntent(IccProfile::fromFile('ISOcoated_v2.icc'), 'FOGRA39', 'Coated FOGRA39');
```

The Info dictionary and the XMP packet are written at `close()`; a `/Metadata` stream is
attached to the catalog unless `xmp` is disabled.

## Streaming and closing

Pages stream to the output as they finish. Fonts, templates and other deferred objects are
written when `close()` runs, together with the page tree, catalog, Info dictionary and
cross-reference table. A document that was never given a page cannot be closed.

## Shared objects

Anything written through `Document::shared($key, $factory)` is written once per document and
reused: ICC streams, shadings, extended graphics states and so on. Images are deduplicated by
content hash, imported PDFs by path, SVG files by path and converter.
