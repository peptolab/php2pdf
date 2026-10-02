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

TrueType and CFF-flavoured OpenType fonts are supported. Both are subset to the glyphs used
when the document closes. A CFF font is cut down to the charstrings and subroutines those glyphs
need, name-keyed and CID-keyed alike, and written as a bare CID-keyed CFF program. Every font is
written as a Type0 font with Identity-H encoding and a ToUnicode map, so text copies and searches
correctly.

Variable fonts draw their default instance. TrueType ones (glyf outlines) carry it as their
outlines already; for CFF2 ones the default instance is worked out from the blended outlines and
embedded as an ordinary CFF subset, so every reader draws it. Other instances along the font's
axes are not available yet.

Colour fonts with layered glyphs (COLR version 0, as in many emoji and icon fonts) draw each
colour glyph as its layers, in the colours of the font's first palette (CPAL); a layer marked for
the text colour takes the current fill colour. The glyph underneath is set invisibly, so the text
still copies and searches. Glyphs defined only as COLR version 1 paint graphs are drawn in one
colour, with a warning, and fonts with only bitmap glyphs (sbix, CBDT) are refused when loaded,
since they have no outlines to embed.

If a CFF font cannot be subset (for example, it builds accented glyphs with the deprecated
`seac` form of `endchar`), it is embedded whole instead and a warning is recorded:

```php
$doc->close();
$fonts->warnings();   // ['Font "X" is embedded whole because its CFF outlines could not be subset: ...']
```

The same file loaded twice yields the same `Font`; fonts belong to the document they were
loaded into.

Parsed font files and search-path indexes are shared between documents in the same process,
so a long-running worker parses each font once. Both caches are bounded: the 32 most recently
used font files, up to 64 MiB of font data, and 64 search-path directories. A file or directory
that changes on disk is read again. `OpenTypeFont::clearCache()` and `FontRegistry::clearCache()`
empty them.

### Ligatures

Fonts with standard ligatures (the OpenType `liga` feature: `fi`, `fl`, `ffi`, `ft` and the
like, as each font defines them) use them by default, as PDFlib, InDesign and HarfBuzz do. Kerning
is applied to the ligature glyph, widths are measured with it, and copied or searched text still
gives the separate letters. `TextOptions(ligatures: false)` or `TextStyle(ligatures: false)` turns
them off, and tracking or character spacing turns them off too. In a Textflow a hyphenation
point never falls inside a ligature: the letters are set separately when a break may come between
them. The standard 14 fonts have no ligatures.

### OpenType features

Other features of the font are asked for by tag, on `TextStyle` or `TextOptions`:

```php
$figures = new TextStyle(font: 'Source Serif 4', features: ['tnum', 'onum']);   // tabular old-style figures
$caps    = new TextStyle(font: 'Source Serif 4', features: ['smcp', 'c2sc']);   // all small caps
$canvas->drawText('1/2', $font, 12, 72, 700, new TextOptions(features: ['frac']));
```

`tnum` and `pnum` (tabular and proportional figures), `onum` and `lnum` (old-style and lining),
`smcp` and `c2sc` (small caps from lower and upper case), `case` (case-sensitive forms), `frac`,
`sups`, `subs`, contextual `calt` and discretionary `dlig` ligatures all work when the font has
them. Single, alternate (the first alternate), ligature and chained context substitutions are
applied, in the font's lookup order, matching HarfBuzz. A small-cap or other substituted glyph
copies as the character it stands for. Tracking and character spacing turn off ligatures only.

### Missing glyphs

A character the font has no glyph for is drawn as the font's `.notdef` glyph, usually a box or
nothing. `DocumentOptions(glyphCheck: ...)` decides what else happens: `GlyphCheck::Warn` (the
default) records a warning in `$doc->fonts()->warnings()` once per font and character,
`GlyphCheck::Error` throws a `PdfException`, and `GlyphCheck::Ignore` does neither. Under PDF/X the
default is `Error`. A missing character never enters the font's ToUnicode map, so copied text
does not pick up a wrong character for it.

### Complex scripts

Right-to-left scripts (Hebrew, Arabic and their relatives), Indic and other South and South East
Asian scripts, and conjoining Hangul jamo need shaping the font engine does not do yet: set as
they are, they would come out wrong. By default such text throws a `PdfException` naming the
script and character. `DocumentOptions(scriptCheck: ScriptCheck::Warn)` sets it unshaped and
records a warning once per font and script; `ScriptCheck::Ignore` sets it silently. Latin, Greek,
Cyrillic, CJK and precomposed Hangul are not affected.

### Fallback fonts

A text style can name fonts to try, in order, for characters its font lacks, as PDFlib's
`fallbackfonts` does:

```php
$style = new TextStyle(font: 'Inter', fallback: ['Helvetica', $notoSymbols]);
```

Each character is set in the first of the font and its fallbacks that has it; spaces stay in the
font of the text around them. Kerning applies within each font's run, and a line's height takes
in every font set on it. A character none of them has follows the missing-glyph policy of the
style's own font. Fallbacks apply to Textflow and table text, which use text styles.

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
