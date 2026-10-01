# Limitations

Known gaps, in rough order of how often they matter.

- **Scripts.** Text is shaped character by character with pair kerning. Ligatures, contextual
  forms, Arabic, Indic and CJK vertical text are not supported.
- **CFF fonts** (`.otf` with PostScript outlines) are embedded whole, not subset. TrueType fonts
  are subset.
- **Line breaking** is greedy (first fit). A Knuth–Plass option for justified text is planned.
- **Text wrap around shapes** is not supported; use several boxes instead.
- **Hyphenation patterns** are not bundled. Load TeX or hunspell pattern files whose licence
  suits you with `Hyphenator::fromFile()`.
- **SVG**: masks, filters, patterns, markers, text on paths and stroked text are skipped (see
  [svg.md](svg.md)); use `RsvgConverter` for those.
- **PDF import**: encrypted files are rejected; JPX, JBIG2 and CCITT images pass through
  untouched; pages are copied as artwork and cannot be edited.
- **PDF/X-4** output follows ISO 15930-7 structurally; no validator ships with the library, so
  check files with veraPDF in your pipeline.
- **Images** in formats other than JPEG and PNG need `ext-imagick` or `ext-gd`.
- **Not in scope**: forms and annotations other than links, JavaScript, multimedia, tagged PDF,
  encryption, HTML or CSS rendering, editing PDFs in place.
