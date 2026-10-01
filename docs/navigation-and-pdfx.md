# Links, bookmarks and PDF/X-4

## Links

```php
$page->addLink(Box::fromMm(20, 250, 60, 8), 'https://example.com/');
$page->addLink(Box::fromMm(20, 240, 60, 8), 'mailto:hello@example.com');
$page->addLink($box, Destination::page(3));                 // whole page
$page->addLink($box, Destination::top(3, 700));             // scrolled so y = 700 is at the top
$page->addLink($box, Destination::at(3, 72, 500, zoom: 1.5));
$page->addLink($box, Destination::named('chapter-2'));
```

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

Fonts are always embedded. The structure follows ISO 15930-7; check files with Acrobat Preflight
or callas pdfToolbox (veraPDF validates PDF/A and PDF/UA, not PDF/X).

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
