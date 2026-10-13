# Links, bookmarks, PDF/X-4 and PDF/A

## Links

```php
$page->addLink(Box::fromMm(20, 250, 60, 8), 'https://example.com/');
$page->addLink(Box::fromMm(20, 240, 60, 8), 'mailto:hello@example.com');
$page->addLink($box, Destination::page(3));                 // whole page
$page->addLink($box, Destination::top(3, 700));             // scrolled so y = 700 is at the top
$page->addLink($box, Destination::at(3, 72, 500, zoom: 1.5));
$page->addLink($box, Destination::named('chapter-2'));
```

Text in a Textflow links with `<a href="…">` (see [textflow.md](textflow.md)).

`addLink($area, $target, tooltip: 'Opens in a browser')` adds a tooltip viewers show when the
pointer rests on the link. Links draw nothing; style the content underneath. The area is in the page's coordinate mode.
Links may point at pages that do not exist yet: annotations are written when the document closes.

## Named destinations and bookmarks

```php
$doc->addDestination('chapter-2', Destination::top(5, 700));

$outline = $doc->outline();
$two     = $outline->add('Chapter 2', 5);                    // a page number means Destination::page()
$two->add('Section 2.1', Destination::at(5, 0, 400));
$two->add('Section 2.2', Destination::named('chapter-2'), open: false);
```

Destination coordinates are in the target page's PDF user space (origin bottom-left, points).
Named destinations are written to the catalog's name tree (`/Names /Dests`), where links and
bookmarks that refer to them by name are looked up.

## PDF/X-4

```php
$doc = new Document('print.pdf', new DocumentOptions(
    pdfx: PdfX::X4,
    rgbProfile: IccProfile::fromFile('sRGB.icc'),
));
$doc->info()->setTitle('Brochure');
$doc->setOutputIntent(IccProfile::fromFile('ISOcoated_v2_300_eci.icc'), 'FOGRA39', 'Coated FOGRA39 (ISO 12647-2:2004)');
```

What the option does:

- writes PDF 1.6 and declares `GTS_PDFXVersion` and `Trapped` in the Info dictionary and the XMP
  packet (`pdfxid:GTS_PDFXVersion`, `pdf:Trapped`);
- gives every page a trim box (the crop or media box when none was set) and a transparency
  group in the output intent's colour space;
- writes `xmpMM:VersionID` and `xmpMM:RenditionClass` in the XMP packet;
- adds a `DefaultRGB` ICCBased colour space to every resource dictionary, so RGB colours and
  images are device-independent;
- refuses links that overlap the bleed box (the trim box when no bleed box is set), and closing
  without an output intent or a title. Links in the slug, outside the printed area, are allowed;
- refuses a page with both a trim box and an art box, a bleed box outside the media box, and a
  trim or art box outside the bleed box.

Page boxes are checked as they are set, and a link as soon as it is added once the page has a
bleed, trim or art box, so the exception comes from the call that broke the rule and the refused
box or link is not kept. When moving boxes that are already set, keep each step valid: enlarge
the bleed box before the trim box, and shrink the trim box before the bleed box. A link added
before any box is set is checked when the page is finished, before anything of the page is
written; a page that fails stays open, so it can be corrected and the document closed.

Fonts are always embedded, and an imported page whose fonts are not is refused. The structure
follows ISO 15930-7; check files with Acrobat Preflight or callas pdfToolbox (veraPDF validates
PDF/A and PDF/UA, not PDF/X).

### Colour

Colours, gradients and images are checked against the output intent's colour space when they
are first used, so the error points at the offending call:

| Device colour | Grey intent | RGB intent | CMYK intent |
|---|---|---|---|
| `Gray` | yes | yes | yes |
| `Rgb` | with `rgbProfile` | yes | with `rgbProfile` |
| `Cmyk` | no | no | yes |

A `Spot` colour is checked by its alternate. ICC-based images and colours are always allowed;
a palette image is checked by its base colour space. Set the output intent before drawing in
colour.

Imported pages are scanned for the device colour spaces they draw with (in their content, forms,
images, shadings and patterns) and must match the output intent itself: `rgbProfile` does not
apply to them, because their `DefaultRGB` resources are their own. Convert artwork to the
output intent's colour space before importing it.

## PDF/A

```php
$doc = new Document('archive.pdf', new DocumentOptions(pdfa: PdfA::A2b));
$doc->setOutputIntent(IccProfile::fromFile('sRGB.icc'), 'sRGB IEC61966-2.1');
```

`PdfA::A2b` writes PDF/A-2b (ISO 19005-2) and `PdfA::A3b` PDF/A-3b (ISO 19005-3), level B: the
document looks the same wherever and whenever it is opened. What the option does:

- writes PDF 1.7 (1.6 together with PDF/X-4) and declares the part and level in the XMP packet
  (`pdfaid:part`, `pdfaid:conformance`), with an extension schema for any property PDF/A does not
  predefine;
- writes the output intent a second time as `GTS_PDFA1`, sharing its profile, when PDF/X-4 is
  set too;
- needs the output intent before any colour is drawn, and checks colours, images and imported
  pages against it as PDF/X does (see the table above);
- refuses the standard 14 fonts, which are not embedded, and imported pages using fonts that are
  not;
- marks links to print, as PDF/A asks of every annotation, and leaves out the automatic states
  of imported layers.

No title is needed. Transparency, layers, object streams and flattened annotations are all
allowed. Check files with [veraPDF](https://verapdf.org), which the library's own PDF/A tests
pass.

### Attachments and Factur-X invoices

PDF/A-3 allows files of any kind to be attached, each with its relationship to the document;
PDF/A-2 allows only PDF/A files, which the library cannot check, so it refuses attachments, as
PDF/X does. Without either standard any file can be attached.

```php
$doc->attachFile('figures.csv', $csv, 'text/csv', AttachmentRelationship::Data, 'The figures');
```

A Factur-X invoice (ZUGFeRD 2 in Germany) is a PDF/A-3b document carrying its invoice as XML.
`attachFacturX()` attaches the XML as `factur-x.xml` with the relationship its profile calls for,
and declares the invoice in the XMP metadata with its extension schema:

```php
$doc = new Document('invoice.pdf', new DocumentOptions(pdfa: PdfA::A3b));
$doc->setOutputIntent(IccProfile::fromFile('sRGB.icc'), 'sRGB IEC61966-2.1');
// ... the invoice's pages ...
$doc->attachFacturX($xml, FacturXProfile::En16931);
```

The XML itself is yours to produce and validate; the Mustang project's validator checks the
whole invoice, PDF and XML.
