# Fonts and text

## Loading fonts

```php
$fonts = $doc->fonts();
$sans  = $fonts->load('/fonts/Inter-Regular.ttf');       // by path; .ttc faces by index
$fonts->addSearchPath('/fonts');
$bold  = $fonts->load('Inter-Bold.ttf');                 // relative to the search paths
$inter = $fonts->family('Inter');                        // all faces of a family found in the search paths
$inter->regular(); $inter->bold(); $inter->italic(); $inter->boldItalic(); $inter->has(FontStyle::Bold);
$fonts->find('Inter Bold');                              // PostScript name, full name, "Family Style" or file name
$fonts->faces();                                         // FontInfo for every face in the search paths
```

TrueType and CFF-flavoured OpenType fonts are supported. TrueType fonts are subset to the
glyphs used when the document closes; CFF fonts are embedded whole. Every font is written as a
Type0 font with Identity-H encoding and a ToUnicode map, so text copies and searches correctly.

The same file loaded twice yields the same `Font`; fonts belong to the document they were
loaded into.

### Standard 14 fonts

When no font file matches, `find()` falls back to the standard 14 fonts that every PDF reader
provides: Helvetica, Times-Roman and Courier, each with bold, italic/oblique and bold italic
variants, plus Symbol and ZapfDingbats. The enum names them directly:

```php
$helvetica = $fonts->find('Helvetica');                  // or 'Helvetica-Bold', 'times bold italic'...
$times     = $fonts->standard(StandardFont::TimesRoman);
new TextStyle(font: 'Helvetica', bold: 'Helvetica-Bold');
```

They return a `CoreFont`. Like `Font`, it implements `FontInterface`, so it works anywhere a font
does. Standard fonts are written as simple Type1 fonts and are not embedded, so they add almost
nothing to the file. Widths and kerning come from Adobe's AFM metrics, which keeps line breaking
and alignment exact. A font file in the search paths with the same name wins.

They cover WinAnsiEncoding only (Western European Latin, plus symbols such as € — “ ”).
Shaping any other character throws a `PdfException`; load a font file for that text instead.
Symbol and ZapfDingbats use their own character sets. PDF/X requires embedded fonts, so these
fonts are refused under `DocumentOptions(pdfx: ...)`.

## Metrics

```php
$sans->width('Hello', 12);          // advance width in points, kerning included
$sans->ascender(12); $sans->descender(12); $sans->capHeight(12); $sans->xHeight(12); $sans->lineGap(12);
$sans->underlinePosition(12); $sans->underlineThickness(12);
$sans->shape('Hello');              // the glyph run (ids, advances, kerning) the text becomes
```

Kerning uses GPOS pair adjustment when present, else the `kern` table.

## Drawing a line of text

`Canvas::drawText($text, $font, $size, $x, $y, ?TextOptions)` shows text with its baseline at
`(x, y)`. `TextOptions` carries character spacing, word spacing, tracking (thousandths of an em),
horizontal scaling, rise, render mode (fill, stroke, clip...) and whether to kern.

## Fitting a line into a box

```php
$placement = $page->fitTextline('Headline', $bold, 24, Box::fromMm(20, 250, 170, 15), TextlineOptions::at(Position::centerLeft()));
$page->fitTextline($title, $bold, 36, $box, TextlineOptions::shrinkToFit(Position::center(), shrinkLimit: 0.6));
$page->fitTextline($label, $sans, 8, $box, new TextlineOptions(Position::topRight(), rotate: 90, underline: true));
```

`TextlineOptions` combines a position in the box, a fit method (`NoFit` by default; `Auto`
shrinks the type size down to `shrinkLimit` when the line is too wide), rotation, text options,
underline and strikeout. The returned `TextlinePlacement` reports the bounds, baseline origin,
font size used, width, and whether the line fits.

For paragraphs and text across pages, see [textflow.md](textflow.md).
