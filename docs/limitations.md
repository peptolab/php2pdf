# Limitations

Known gaps, in rough order of how often they matter.

- **Scripts.** Latin, Greek, Cyrillic, Hebrew, Arabic and the other joining scripts are shaped
  with all of the font's substitutions and positioning, matching HarfBuzz, and right-to-left text
  is ordered by the Unicode bidi algorithm. Indic and South East Asian scripts, Tibetan, Mongolian,
  conjoining Hangul jamo and CJK vertical text are not shaped by the library itself; text in those
  scripts is refused unless HarfBuzz is set as `DocumentOptions::$complexShaper` (see
  [Complex scripts](fonts-and-text.md#complex-scripts)) or `DocumentOptions::$scriptCheck` says
  otherwise. CJK vertical text is not supported. Tab stops measure from the left in
  right-to-left lines, right-to-left words are not hyphenated, Arabic justification does not
  stretch letters with kashida, and Syriac abbreviation marks (`stch`) are not stretched.
- **Variable fonts** draw their default instance only; named instances and axis values are not
  supported.
- **Soft masks** (and SVG masks) are placed wrongly by macOS Preview inside artwork placed scaled or
  moved; other readers follow the specification.
- **Colour fonts**: COLR version 0 layers are drawn in colour; COLR version 1 paint graphs
  (gradients, transforms) are drawn in one colour, SVG glyphs from their outlines, and fonts with
  only bitmap glyphs (sbix, CBDT) cannot be used.
- **CFF fonts** whose charstrings use `seac` accent composition or the arithmetic and storage
  operators are embedded whole, with a warning in `$doc->fonts()->warnings()`; others are subset.
- **Standard 14 fonts** cover WinAnsiEncoding only (Symbol and ZapfDingbats have their own
  sets), and are not allowed under PDF/X.
- **Text wrap around shapes** is not supported; use several boxes instead.
- **Hyphenation patterns** are not bundled. Load TeX or hunspell pattern files whose licence
  suits you with `Hyphenator::fromFile()`.
- **SVG**: filters, text on paths and stroked text are skipped (see
  [svg.md](svg.md)); use `RsvgConverter` for those.
- **PDF import**: encrypted files are rejected; JPX, JBIG2 and CCITT images pass through
  untouched; pages are copied as artwork and cannot be edited. Layers (optional content) keep
  their default visibility, but macOS Preview ignores layers inside placed artwork and shows
  hidden ones unless they are removed with `HiddenLayers::Remove`. Visibility expressions
  (`/VE`) in membership dictionaries are not evaluated; their `/P` policy is used.
- **PDF/X-4** output follows ISO 15930-7 structurally; no validator ships with the library, so
  check files with Acrobat Preflight or callas pdfToolbox. Imported pages are checked for device
  colour spaces but not converted.
- **Images** in formats other than JPEG, JPEG 2000, PNG and baseline TIFF (GIF, WebP, BMP, and
  TIFF layouts such as tiles or YCbCr) need `ext-imagick` or `ext-gd`.
- **Not in scope**: forms and annotations other than links, JavaScript, multimedia, tagged PDF,
  encryption, HTML or CSS rendering, editing PDFs in place.
