# Images

```php
$photo = $doc->image('photo.jpg');               // or Document::imageFromData($bytes)
$page->place($photo, Box::fromMm(20, 200, 80, 60), Fit::meet());
$photo->widthPx(); $photo->heightPx(); $photo->dpiX(); $photo->naturalSize(); $photo->hasTransparency();
```

An image is written once however many times it is loaded or placed: files are deduplicated by
content.

## Formats

- **JPEG**: dimensions and colour space are read from the markers and the data passes through
  untouched (DCTDecode). CMYK JPEGs from Adobe applications are inverted correctly, and an
  embedded ICC profile becomes an ICCBased colour space. The EXIF orientation from cameras and
  phones is honoured: the image is drawn mirrored or turned as it says, without re-encoding, and
  a quarter turn swaps its natural width and height.
- **JPEG 2000** (`.jp2` files and bare `.j2k` codestreams): size, components, depth and colour
  space (or embedded ICC profile) are read from the header and the data passes through untouched
  (JPXDecode). An alpha channel is kept in the data (`SMaskInData`); Acrobat and Ghostscript
  honour it, but poppler ignores it and macOS Preview blends it unreliably.
- **PNG**: every colour type and bit depth, palettes, transparency (tRNS as a colour key or a
  soft mask), alpha channels split into a soft mask, interlaced files, resolution and ICC chunks.
  Pixel data stays Flate-compressed with PNG predictors, so nothing is re-encoded.
- **TIFF**: baseline files are read natively, every page: strips uncompressed or compressed with
  LZW, Deflate or PackBits (with the horizontal predictor), grey, RGB, palette and CMYK at 1 to 16
  bits, an unassociated alpha channel as a soft mask, and the embedded ICC profile, resolution
  and orientation. CMYK stays CMYK. Single-strip CCITT Group 3 and 4 fax images pass through
  untouched (CCITTFaxDecode). Tiled, JPEG-compressed, YCbCr and premultiplied-alpha TIFFs go to
  the transcoder below.
- **Other formats** (GIF, WebP, BMP...) are transcoded to PNG with `ext-imagick` when it is
  loaded, else `ext-gd`. Without either, loading them throws.

## Resolution and natural size

The natural size in points comes from the pixel size and the resolution stored in the file
(JPEG JFIF density, or the EXIF resolution when JFIF gives none; PNG pHYs), 72 dpi when none. `Fit::meet()->withDpi(300)` or
`Fit::noFit()->withDpi(300)` overrides it for one placement.

`ImageOptions` sets how a file is loaded:

```php
$doc->image('scan.tif', new ImageOptions(dpi: 300, page: 2, orientation: false));
```

`dpi` replaces the file's resolution for its natural size, `page` picks a page of a multi-page
TIFF or GIF (TIFF natively, others through ext-imagick), and `orientation: false` ignores a
JPEG's EXIF orientation. `clippingPath: true` clips the image to the path Photoshop marks as its
clipping path, in a JPEG or TIFF file, and `clippingPath: 'Outline'` to the path of that name; an
image without the path asked for is an error. Clipping paths are not applied unless asked for.
The same file loaded with different options is a different image.

## Transparency

Images with alpha get an `/SMask`; colour-keyed PNGs get a `/Mask` array. Group opacity and
blend modes from the canvas apply to images like any other content.
