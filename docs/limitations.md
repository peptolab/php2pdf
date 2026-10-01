# Limitations

Known gaps, in rough order of how often they matter.

- **Scripts.** Latin text is shaped with the font's substitutions (standard ligatures, and other
  features asked for by tag) and pair kerning. Multiple, context and reverse chaining
  substitutions (GSUB types 2, 5 and 8), lookup flags such as ignoring marks, mark positioning,
  Arabic, Indic and CJK vertical text are not supported.
- **Variable fonts** draw their default instance only; named instances and axis values are not
  supported.
- **Colour fonts**: COLR version 0 layers are drawn in colour; COLR version 1 paint graphs
  (gradients, transforms) are drawn in one colour, SVG glyphs from their outlines, and fonts with
  only bitmap glyphs (sbix, CBDT) cannot be used.
- **CFF fonts** whose charstrings use `seac` accent composition or the arithmetic and storage
  operators are embedded whole, with a warning in `$doc->fonts()->warnings()`; others are subset.
- **Standard 14 fonts** cover WinAnsiEncoding only (Symbol and ZapfDingbats have their own
  sets), and are not allowed under PDF/X.
- **Line breaking** is greedy (first fit). A Knuth–Plass option for justified text is planned.
- **Text wrap around shapes** is not supported; use several boxes instead.
- **Hyphenation patterns** are not bundled. Load TeX or hunspell pattern files whose licence
  suits you with `Hyphenator::fromFile()`.
- **SVG**: masks, filters, patterns, markers, text on paths and stroked text are skipped (see
  [svg.md](svg.md)); use `RsvgConverter` for those.
- **PDF import**: encrypted files are rejected; JPX, JBIG2 and CCITT images pass through
  untouched; pages are copied as artwork and cannot be edited. Layers (optional content) keep
  their default visibility, but macOS Preview ignores layers inside placed artwork and shows
  hidden ones unless they are removed with `HiddenLayers::Remove`. Visibility expressions
  (`/VE`) in membership dictionaries are not evaluated; their `/P` policy is used.
- **PDF/X-4** output follows ISO 15930-7 structurally; no validator ships with the library, so
  check files with Acrobat Preflight or callas pdfToolbox. Imported pages are checked for device
  colour spaces but not converted.
- **Images** in formats other than JPEG and PNG need `ext-imagick` or `ext-gd`.
- **Not in scope**: forms and annotations other than links, JavaScript, multimedia, tagged PDF,
  encryption, HTML or CSS rendering, editing PDFs in place.
