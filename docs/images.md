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
- **PNG**: every colour type and bit depth, palettes, transparency (tRNS as a colour key or a
  soft mask), alpha channels split into a soft mask, interlaced files, resolution and ICC chunks.
  Pixel data stays Flate-compressed with PNG predictors, so nothing is re-encoded.
- **Other formats** (GIF, WebP, BMP, TIFF...) are transcoded to PNG with `ext-imagick` when it
  is loaded, else `ext-gd`. Without either, loading them throws.

## Resolution and natural size

The natural size in points comes from the pixel size and the resolution stored in the file
(JPEG JFIF density, or the EXIF resolution when JFIF gives none; PNG pHYs), 72 dpi when none. `Fit::meet()->withDpi(300)` or
`Fit::noFit()->withDpi(300)` overrides it for one placement.

`ImageOptions` sets how a file is loaded:

```php
$doc->image('scan.tif', new ImageOptions(dpi: 300, page: 2, orientation: false));
```

`dpi` replaces the file's resolution for its natural size, `page` picks a page of a multi-page
TIFF or GIF (through ext-imagick), and `orientation: false` ignores a JPEG's EXIF orientation.
The same file loaded with different options is a different image.

## Transparency

Images with alpha get an `/SMask`; colour-keyed PNGs get a `/Mask` array. Group opacity and
blend modes from the canvas apply to images like any other content.
