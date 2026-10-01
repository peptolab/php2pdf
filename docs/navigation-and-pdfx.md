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

Links draw nothing; style the content underneath. The area is in the page's coordinate mode.
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
- adds a `DefaultRGB` ICCBased colour space to every resource dictionary, so RGB colours and
  images are device-independent;
- refuses RGB colours, gradients and images without `rgbProfile`, link annotations, and closing
  without an output intent or a title.

The structure follows ISO 15930-7. Validate with veraPDF in your pipeline; CMYK and spot
colours need no profile, and fonts are always embedded.
