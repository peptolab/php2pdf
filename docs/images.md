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
  Opaque pixel data stays Flate-compressed with PNG predictors, so nothing is re-encoded; 16-bit
  images with alpha keep 16-bit colour and a 16-bit soft mask.
- **TIFF**: baseline files are read natively, every page: strips uncompressed or compressed with
  LZW, Deflate or PackBits (with the horizontal predictor), grey, RGB, palette and CMYK at 1 to 16
  bits, an unassociated alpha channel as a soft mask (16-bit when the samples are), and the
  embedded ICC profile, resolution and orientation. CMYK stays CMYK. Single-strip CCITT Group 3
  and 4 fax images pass through untouched (CCITTFaxDecode). Tiled, separate-plane,
  JPEG-compressed, YCbCr, premultiplied-alpha and mixed-depth TIFFs go to the transcoder below.
- **Other formats** (GIF, WebP, BMP...) are transcoded to PNG with `ext-imagick` when it is
  loaded, else `ext-gd`. Without either, loading them throws.

## Conversions that would lose data

Transcoding is refused, with a `ParseException` naming the file and each loss, when the PNG would
not keep everything known of the source:

- a colour model PNG cannot hold: CMYK, other ink separations, CIELab or LogLuv, which would
  become RGB;
- an embedded ICC profile, which is not carried through;
- samples deeper than the PNG produced (more than 16 bits, or more than 8 through `ext-gd`);
- an alpha channel or transparency the PNG does not keep.

What is known comes from the file itself before any conversion (a TIFF's directory, the ICC
flags of WebP, AVIF and HEIF headers, an AVIF's depth) and from `ext-imagick` when it reads the
file; the PNG produced is checked against it afterwards. `ext-imagick` is asked for 16-bit
samples and an alpha channel when the source has them. So a GIF, a BMP, or a tiled 8 or 16-bit
grey or RGB TIFF without a profile converts as before, and a CMYK TIFF does not:

```php
$doc->image('tiled-cmyk.tif');                                              // ParseException
$doc->image('tiled-cmyk.tif', new ImageOptions(allowLossyConversion: true)); // RGB, profile dropped
```

`allowLossyConversion: true` accepts the loss: CMYK and CIELab are converted to sRGB by
`ext-imagick` (with their profile removed, since it no longer describes the samples), and the rest
are kept as far as PNG allows. Under PDF/X, an image converted to RGB without a profile is still
refused when it is placed if the output intent is CMYK; converting the file to a layout read
natively, such as a stripped, contiguous TIFF, keeps its colour exactly.

## Decoders of your own

A decoder of your own reads a format natively, or takes over one the library reads. Implement
`ImageDecoderInterface` and add it with `ImageDecoders::with()`, which tries it before the
built-in decoders; the document reads every image through `DocumentOptions::$imageDecoders`,
images in SVGs included. Check `$limits` as soon as the size is known, before decoding samples.
A decoder that recognises a file but not its layout returns `ImageTraits` saying what a
conversion would have to keep, and the file is converted to PNG as above.

```php
final class PgmDecoder implements ImageDecoderInterface
{
    public function accepts(string $data): bool
    {
        return str_starts_with($data, 'P5');
    }

    public function hasPages(): bool
    {
        return false;
    }

    public function name(): string
    {
        return 'PGM';
    }

    public function decode(string $data, Limits $limits, ImageOptions $options): ImageData|ImageTraits
    {
        $m = [];
        if (1 !== preg_match('/^P5\s+(\d+)\s+(\d+)\s+255\s/', $data, $m)) {
            throw new ParseException('Only 8-bit binary PGM files are read.');
        }
        $width  = (int) $m[1];
        $height = (int) $m[2];
        $limits->assertImagePixels($width, $height);

        return new ImageData(new Raster($width, $height, 8, ImageColorSpace::gray(), substr($data, strlen($m[0]))));
    }
}

$doc = new Document('out.pdf', new DocumentOptions(
    imageDecoders: ImageDecoders::default()->with(new PgmDecoder()),
));
$page->place($doc->image('scan.pgm'), Box::fromMm(20, 20, 80, 60));
```

`Raster` takes the samples as they are, or encoded with a PDF filter (`DCTDecode`, `JPXDecode`,
`FlateDecode` with its `decodeParms`, `CCITTFaxDecode`); an alpha channel goes in a `SoftMask`.

## Resolution and natural size

The natural size in points comes from the pixel size and the resolution stored in the file
(JPEG JFIF density, or the EXIF resolution when JFIF gives none; PNG pHYs), 72 dpi when none. `Fit::meet()->withDpi(300)` or
`Fit::noFit()->withDpi(300)` overrides it for one placement.

`ImageOptions` sets how a file is loaded:

```php
$doc->image('scan.tif', new ImageOptions(dpi: 300, page: 2, orientation: false));
```

`dpi` replaces the file's resolution for its natural size, `page` picks a page of a multi-page
TIFF or GIF (TIFF natively, others through ext-imagick), `orientation: false` ignores a JPEG's
EXIF orientation, and `allowLossyConversion: true` accepts a transcode that loses data (see
above). `clippingPath: true` clips the image to the path Photoshop marks as its
clipping path, in a JPEG or TIFF file, and `clippingPath: 'Outline'` to the path of that name; an
image without the path asked for is an error. Clipping paths are not applied unless asked for.
The same file loaded with different options is a different image.

## Transparency

Images with alpha get an `/SMask`, at 16 bits per sample when the image has 16-bit samples;
colour-keyed PNGs get a `/Mask` array. Group opacity and
blend modes from the canvas apply to images like any other content.
