# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/); before 1.0, minor versions may change the API.

## [Unreleased]

### Added

- Image decoders of your own: `ImageDecoderInterface`, added with `ImageDecoders::with()` and set
  as `DocumentOptions::$imageDecoders`, for formats the library does not read or in place of one
  it does. `ImageTraits` is public, for a decoder that leaves a layout to the converter.

### Changed

- Text styles are resolved once, and the cells of a table share their resolved styles rather than
  each making its own: a 2,000-row table takes about a quarter less time and memory.
- An SVG marker is drawn once, as a form placed at every vertex it marks, rather than drawn again
  at each one.
- Streams are written to the output without being copied, and a written image keeps none of its
  pixels: placing an 11.7 MB JPEG peaks at 1.2 times its size rather than 3.2, and a document with
  many images no longer holds all of their samples until it closes.

### Fixed

- TIFFs with 16-bit samples and the horizontal predictor (as ImageMagick writes LZW TIFFs) are read
  natively; they were refused as needing a conversion that would lose data.

## [0.1.0] - 2026-10-02

The first release.

### Documents and pages

- Pages stream to the output as they finish; files are written atomically through a temporary file.
- Page boxes (media, crop, bleed, trim, art), rotation, top-down coordinates, metadata in Info and XMP.
- Links with tooltips, named destinations in the name tree, and bookmarks.
- `flow()` drains Textflows and tables across pages and through several frames per page.
- `onClose()` callbacks run while the page count is final, for "Page X of Y" footers.
- `objects()` writes PDF objects of your own; custom `PlaceableInterface` artwork is first-class.
- PDF/X-4: output intents, colour checks against the intent, imported artwork checks, page box rules,
  links allowed only outside the printed area.
- Resource limits (`Limits`) for hostile PDFs, images and SVG, covering object nesting, SVG drawing
  depth and reference expansion, and the pixel limit checked from every image format's header
  before decoding; circular SVG references refused; exception subtypes for parse, conformance and
  layout failures.

### Text

- TrueType and CFF OpenType fonts subset on embedding; the standard 14 fonts by name.
- Kerning across word spaces and hyphenation points; left side bearings kept in subsets.
- Standard ligatures (`liga`) by default, matching HarfBuzz glyph for glyph; copied text expands them.
- Variable fonts with CFF2 outlines, at their default instance.
- Colour fonts with COLR version 0 layers, in their palette colours; bitmap-only fonts refused with a clear error.
- OpenType features by tag (`tnum`, `onum`, `smcp`, `c2sc`, `case`, `frac`, `sups` and others), with
  HarfBuzz's default features; every GSUB and GPOS lookup type but cursive attachment, with lookup
  flags and the font's features for each script and language (localised forms for the style's
  `language`).
- Mark positioning: accents placed on their letters by the font's anchors, glyph offsets in `Glyph`,
  and Unicode normalisation for the font as HarfBuzz does it. Each glyph's `cluster` is the
  character of the text it stands for, so normalised text keeps its word boundaries.
- Right-to-left text: the Unicode bidi algorithm (passing Unicode's conformance tests) for lines,
  SVG text and Textflow paragraphs, mirrored characters, `TextDirection` on `TextOptions` and
  `TextStyle`; Hebrew shaped as HarfBuzz shapes it.
- Arabic, Syriac, NKo, Adlam and the other joining scripts: joining forms, the Arabic shaper's
  feature stages, presentation-form fallback, cursive attachment and modifier mark ordering, as
  HarfBuzz does them.
- Fallback font stacks; a missing-glyph policy (`GlyphCheck`), and a policy for scripts the engine cannot shape yet (`ScriptCheck`, refusing them by default).
- Scripts the engine does not shape (Indic, South East Asian, Tibetan and others) can be handed to
  HarfBuzz through FFI or the `hb-shape` command: `DocumentOptions::$complexShaper`, found by
  `HarfBuzz::detect()`.
- Shaped text is shared by every document using a font, and shaping time grows in step with the
  text, ligatures and all.
- Colour glyphs copy and search as their characters once, not once per layer.
- Textflow: styles, markup, a builder, box chaining, copy fitting, tabs with leaders, hyphenation, widows and
  orphans, inline artwork, the end-of-text point; breaks after hyphens, dashes and slashes, and
  fixed-width Unicode spaces.
- Justified paragraphs are composed whole (Knuth–Plass), evening their word spacing; `composer`
  chooses per style, and `justification` sets how far word spaces may shrink and stretch. A
  justified line's spaces change in proportion to their own widths.
- Links over Textflow text (`<a href>`, `->link()`), and matchboxes: the areas of marked text after a fit.
- `fitTextline()` for single lines with fit methods.

### Tables

- Fixed, percentage and content-sized columns; spans; padding, borders and backgrounds.
- Header rows and footer rows repeated in every box, footers filled per box for carried-forward totals.
- Row groups kept together; rows split across boxes.

### Images and artwork

- JPEG (with EXIF orientation and resolution), JPEG 2000 and CCITT fax passed through untouched.
- PNG in every colour type and depth, interlaced files included; 16-bit alpha stays a 16-bit soft mask.
- Baseline TIFF natively, keeping CMYK, palettes, ICC profiles and 16-bit alpha, on any page.
- Photoshop clipping paths in JPEG and TIFF files.
- Other formats through ext-imagick or ext-gd, refused when the conversion would lose data (CMYK or
  CIELab turned to RGB, an ICC profile, bit depth or alpha) unless `allowLossyConversion` accepts it.
- DeviceN colours: several inks in one fill, mixed like overprinted inks where they are not printed.
- Lab colours and Lab spot alternates; `SpotLibrary` loads spot colours from ASE swatch files or CSV tables.
- Soft masks (luminosity and alpha) on the canvas, and SVG `<mask>`.
- Tiling patterns on the canvas (`setFillPattern()`, `setStrokePattern()`), and SVG `<pattern>`.
- SVG markers at path vertices, oriented along the path.
- Gradients that repeat or reflect beyond their vector; SVG colours with alpha, `hsl()` and `spreadMethod`.
- The fit engine: `meet`, `slice`, `entire`, `clip`, `noFit`, `auto`, with positions and rotation.
- Templates; pages of PDF and AI files imported as artwork, with their layers kept or removed.
- SVG converted natively (paths, gradients, clip paths, text, images) or through rsvg-convert.
- Images, imported PDFs and SVGs are read, converted and written once however often they are
  placed; an image placed at several resolutions, orientations or clips shares its pixels, and an
  image file is read again only when it changes.

[Unreleased]: https://github.com/peptolab/php2pdf/compare/0.1.0...HEAD
[0.1.0]: https://github.com/peptolab/php2pdf/releases/tag/0.1.0
