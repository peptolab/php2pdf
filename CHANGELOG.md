# Changelog

All notable changes to this project are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project uses
[Semantic Versioning](https://semver.org/); before 1.0, minor versions may change the API.

## [Unreleased]

## [0.1.9] - 2026-10-13

### Changed

- Embedded fonts number their glyphs in the order a document first uses them, so a subset holds
  only the glyphs used rather than every glyph up to the highest one used: a page of text in three
  fonts is about a third smaller. Text, widths and copied text are unchanged; a font's codes now
  depend on the order its glyphs appear in.

## [0.1.8] - 2026-10-13

### Fixed

- Plain text no longer breaks lines inside characters whose UTF-8 form ends in the byte 0x85,
  such as Å and much of Cyrillic (х): a textflow of "очень хорошо" was split in two and lost text
  around the break. Hyphenation patterns and CSV swatch names with such characters are read whole
  too. Plain text now also breaks lines at the line and paragraph separators (U+2028, U+2029).

## [0.1.7] - 2026-10-13

### Added

- `FlowOptions::$avoidEmptyBegin` leaves out the empty lines and empty paragraphs a box would start
  with, as PDFlib's `avoidemptybegin` does. It is off by default.
- JBIG2 image files are read natively, any page, and passed through untouched (JBIG2Decode), with
  the segments every page shares as a JBIG2Globals stream written once. `Raster::$jbig2Globals`
  carries them for a decoder of your own.

### Changed

- What the words of a document's text show is remembered by the document rather than by the
  process, so a long-running worker keeps nothing of one document's text into the next.

## [0.1.6] - 2026-10-12

### Added

- `Table::measureUpTo($width, $limit)` measures the remaining rows only until their height passes
  the limit and returns a `TableMeasure` (height, rows measured, `fits()`), so asking whether a long
  table fits a box no longer lays out the whole table.

### Changed

- Text is set faster: a line is drawn from per-word pieces worked out once per run and font, each
  paragraph's setup is made once, and the bidi and link passes are skipped when a line has
  neither. Words and glyphs are shared across paragraphs, pair kerning is remembered by glyph
  pair, and font subsets copy their metrics rather than rebuild them. Flowed text takes 21 to 28%
  less time and justified text 24% less; the output is byte for byte the same.
- Tables build a row's cells when the row is first needed and let them go once drawn, so their
  memory no longer grows with their length: a 2,000-row table takes 25% less time and grows the
  process by 14 MiB instead of 54 MiB.
- A cell's markup or font error now surfaces when its row is reached, not when the table is first
  fitted, so the pages before it may already be drawn.
- PNG images decode faster, interlaced ones and large RGBA ones especially; a 12-megapixel RGBA
  image peaks 2 MiB higher in PHP's heap while it decodes, though its resident memory is lower.
- A document makes its text context and image decoders when it first needs them, so one without
  text or images starts faster.
- `IccProfile::key()` returns an xxh128 digest instead of md5. It has the same length and format and
  is still a stable key for deduplicating, but its value differs from before.

### Fixed

- The tables guide gave `measure()` a document argument it does not take.
- A dictionary entry whose key is a numeric string (`"1"`) is written out; it threw a `TypeError`.

## [0.1.5] - 2026-10-08

### Fixed

- Document information, bookmarks, link tooltips and other PDF text strings refuse text that is
  not valid UTF-8 with an `InvalidArgumentException`, as they were meant to; ill-formed bytes were
  written as "?" instead.
- `FontInfo::peek()` on a font that cannot be opened, and HarfBuzz shaping when the `hb-shape`
  command cannot be run, throw their exception without a PHP warning first.

## [0.1.4] - 2026-10-08

### Fixed

- Every file loader (fonts, images, SVG, PDF, ICC profiles, swatches, hyphenation patterns)
  reports a missing file as "not found" and an unreadable one as "Cannot read" with an
  `IoException`, without a PHP warning first. A directory is refused as not found: before, a
  hyphenation patterns path naming one gave a hyphenator with no patterns, and an ICC profile path
  an `InvalidArgumentException`. An SVG image file that cannot be read is left out with a warning,
  as a missing one is.

## [0.1.3] - 2026-10-03

### Changed

- Text is set faster and in less memory: shaping, line breaking, line assembly and drawing each do
  less work per glyph. Flowed text takes 20 to 26% less time and table text 13% less, and the
  memory a document grows by roughly halves (a 2,000-row table: 79 MiB to 55 MiB).
- A font's shaped runs are shared between documents within a budget of bytes as well as a count,
  so a long-running worker no longer keeps hundreds of MiB of runs that never repeat.
- A Textflow shares identical words, spaces and breaks, so its memory grows with its vocabulary
  rather than its length.
- A hyphenated line is drawn as one text object rather than split at each hyphenation point it
  does not break at: the output changes, and a hyphenated document is about half the size, but it
  looks the same.

### Fixed

- An image file that exists but cannot be read is reported as unreadable, not as missing, and an
  unreadable font file is refused without a PHP warning that it could not be opened.

## [0.1.2] - 2026-10-02

### Added

- Letter spacing and glyph scaling in justification, as in InDesign:
  `Justification::$minLetterSpacing` and `$maxLetterSpacing` (percentages of a word space) and
  `$minGlyphScaling` and `$maxGlyphScaling` (percentages of the glyphs' width). The paragraph
  composer weighs breaks by them, and a justified line takes its slack in word spaces first, then
  between letters, then in the width of its glyphs. By default neither changes, and text is set
  as before.

## [0.1.1] - 2026-10-02

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
- A PNG with an alpha channel is decoded a row at a time, its colour compressed as it is read: a
  65 MB file peaks at 3.4 times its size rather than 7.
- A TIFF is read a strip at a time from the file, its predictor, byte order and alpha worked on in
  whole rows as they come: a 37 MB CMYK file peaks at 3.6 times its size rather than 4.6.

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

[Unreleased]: https://github.com/peptolab/php2pdf/compare/0.1.9...HEAD
[0.1.9]: https://github.com/peptolab/php2pdf/compare/0.1.8...0.1.9
[0.1.8]: https://github.com/peptolab/php2pdf/compare/0.1.7...0.1.8
[0.1.7]: https://github.com/peptolab/php2pdf/compare/0.1.6...0.1.7
[0.1.6]: https://github.com/peptolab/php2pdf/compare/0.1.5...0.1.6
[0.1.5]: https://github.com/peptolab/php2pdf/compare/0.1.4...0.1.5
[0.1.4]: https://github.com/peptolab/php2pdf/compare/0.1.3...0.1.4
[0.1.3]: https://github.com/peptolab/php2pdf/compare/0.1.2...0.1.3
[0.1.2]: https://github.com/peptolab/php2pdf/compare/0.1.1...0.1.2
[0.1.1]: https://github.com/peptolab/php2pdf/compare/0.1.0...0.1.1
[0.1.0]: https://github.com/peptolab/php2pdf/releases/tag/0.1.0
