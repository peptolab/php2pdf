# Documents and pages

## Creating a document

```php
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Core\Output\StreamOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;

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
| `producer` | `'Php2Pdf'` | Written to the Info dictionary and XMP |
| `xmp` | `true` | Write an XMP metadata packet |
| `topDown` | `false` | Default coordinate origin of pages |
| `pdfx` | `null` | Write to a PDF/X level (see [navigation-and-pdfx.md](navigation-and-pdfx.md)) |
| `rgbProfile` | `null` | An ICC profile RGB colours are interpreted in |
| `glyphCheck` | `Warn` (`Error` under PDF/X) | What a character missing from its font does (see [fonts-and-text.md](fonts-and-text.md)) |
| `scriptCheck` | `Error` | What text in a script the engine cannot shape yet does (see [fonts-and-text.md](fonts-and-text.md)) |
| `limits` | `Limits::default()` | Resource limits for the PDFs, images and SVG the document reads |

`Limits` caps what reading a hostile or broken file can cost: `maxDecodedBytes` (256 MiB per
decompressed stream), `maxImagePixels` (100 million), `maxPages` (100,000 per imported PDF),
`maxXrefEntries` (10 million), `maxObjectNesting` (256 levels of PDF arrays and dictionaries,
page tree levels, reference chains and nested resources), `maxNesting` (256 levels of SVG
elements), `maxSvgDepth` (512 levels of SVG drawing, counting nested elements and the
references being expanded within them) and `maxSvgWork` (10,000 `<use>`, mask, pattern and
marker expansions per SVG). A file over a limit fails with a `ParseException`, except that a PDF
object nested too deeply is treated like any other broken object: it reads as missing, or
makes the reader rebuild the cross-reference. SVG that refers back to something it is still
expanding (a clip path clipped by itself, a mask drawn through a `<use>` of the element it
masks, and so on) also fails with a `ParseException` naming the loop. The static constructors
(`PdfFile::fromData()`, `SvgFile::fromData()`, `ImageReader::read()`) take a `Limits` too;
lower the limits for files from untrusted sources.

## Handling untrusted files

The library parses these inputs, and any of them may come from a user upload:

- **PDF and AI files** (`import()`, `PdfFile`): the cross-reference, object and page-tree
  parsers and the stream filters, bounded by `maxDecodedBytes`, `maxPages`, `maxXrefEntries`
  and `maxObjectNesting`, with cycles in the page tree detected;
- **images** (`image()`, `imageFromData()`): the PNG decoder and the header readers of other
  formats, bounded by `maxImagePixels` before anything is decoded; formats other than JPEG and
  PNG go to Imagick or GD, which have their own limits;
- **SVG** (`svg()`, `SvgFile`): the XML parser and renderer, bounded by `maxNesting`,
  `maxSvgDepth`, `maxSvgWork` and, for `.svgz`, `maxDecodedBytes`, with circular references
  refused. `<image>` elements read files only from inside the SVG's own directory: absolute
  paths, `..` and links that lead out of it are refused, and SVG read from a string reads no
  files unless `SvgFile::fromData()` is given a directory. Keep untrusted SVG in a directory of
  its own;
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

## Errors

Everything the library throws implements `Php2Pdf\Exception\ExceptionInterface`: catch that to
handle them all. Each is also one of PHP's own exceptions, so it can be caught by kind:

| Exception | Is a | Thrown when |
|---|---|---|
| `InvalidArgumentException` | `\InvalidArgumentException` | an argument is out of range or the wrong kind |
| `StateException` | `\LogicException` | a call the object's state does not allow: drawing on a finished page, using a closed document, `restore()` without `save()` |
| `PdfException` | `\RuntimeException` | something fails at run time; its kinds below say what |
| `ParseException` | `PdfException` | an input file or markup is malformed, unsupported, or over a resource limit |
| `IoException` | `PdfException` | a file cannot be read or written, or a tool cannot be run |
| `ConformanceException` | `PdfException` | the document would break the rules of the standard it declares (PDF/X-4) |
| `LayoutException` | `PdfException` | content cannot be laid out where it was asked to go |

Methods that read files say so with `@throws ParseException` and `@throws IoException`, and the
static analysis checks that every caller in the library declares them too.

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

`onClose($callback)` runs a callback when `close()` is called, before the last page is finished:
the page count is final, templates placed on every page can still be drawn on (a "Page X of Y"
footer; see the cookbook), and so can the last page.

## Shared objects

`$doc->objects()` holds the document's PDF objects. `add($object)` writes one and returns its
reference; `shared($key, $factory)` writes it once per document and returns the same reference
for the same key, as the library does for ICC streams, shadings and extended graphics states.
Images are deduplicated by content hash, imported PDFs by path, SVG files by path and converter.

Artwork of your own (see [fit-engine.md](fit-engine.md)) can write its own resources there with
`Dictionary`, `Stream`, `Name` and `Reference` from `Php2Pdf\Core\Object`, and draw a form or image
XObject with `Canvas::drawXObject($reference, $matrix)`:

```php
$form = $doc->objects()->shared('logo-mark', fn () => new Stream(
    Dictionary::of(['Type' => new Name('XObject'), 'Subtype' => new Name('Form'), 'BBox' => [0, 0, 10, 10]]),
    '0 0 10 10 re f',
));
$canvas->drawXObject($form, $matrix);
```
