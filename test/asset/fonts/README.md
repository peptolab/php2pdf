# Test fonts

- `DejaVuSans.ttf`, `DejaVuSans-Bold.ttf`: DejaVu fonts, see `LICENSE-DejaVu.txt`. Used by the
  unit tests, which check exact metrics and need DejaVu's wide character coverage.
- `SourceSerif4-Regular.ttf`, `SourceSerif4-Bold.ttf`: Source Serif 4 4.005 by Adobe, unmodified,
  TrueType outlines. SIL Open Font License 1.1, see `LICENSE-SourceSerif4.md`.
- `SourceSans3-Regular.otf`, `SourceSans3-Bold.otf`: Source Sans 3 3.052 by Adobe, unmodified,
  name-keyed CFF outlines. SIL Open Font License 1.1, see `LICENSE-SourceSans3.md`.
- `PhpPdfCidTest.otf`: a CID-keyed CFF font derived from Source Sans 3 Regular by
  `tools/make-cid-test-font.py` (Basic Latin only, two Font DICTs, CIDs spread out). As a modified
  version it no longer uses the Reserved Font Name "Source"; it is also licensed under the SIL Open
  Font License 1.1, see `LICENSE-SourceSans3.md`.
- `PhpPdfCursiveTest.ttf`, `PhpPdfArabicFallbackTest.ttf`: small fonts of boxes made by
  `tools/make-shaping-test-fonts.py`, one with cursive attachment (right to left with marks, and
  left to right), one with Arabic presentation forms and no layout features. No outlines from any
  other font are used.

The demo documents in `test/integration` (brochure, invoice, text, table, static layout, image,
import and SVG sheets) are set in Source Serif 4 and Source Sans 3, so they exercise both the
TrueType and the CFF embedding paths. All names in them are fictional.
