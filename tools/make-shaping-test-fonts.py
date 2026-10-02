"""
Builds the shaping test fonts in test/asset/fonts with fontTools:

- PhpPdfCursiveTest.ttf: Arabic letters with cursive attachment under the right-to-left lookup
  flag and a mark, and Latin letters with cursive attachment left to right.
- PhpPdfArabicFallbackTest.ttf: Arabic letters with presentation-form glyphs and lam-alef
  ligatures but no layout features, for the presentation-form fallback.

Usage: python3 tools/make-shaping-test-fonts.py test/asset/fonts
"""
import sys
from fontTools.feaLib.builder import addOpenTypeFeaturesFromString
from fontTools.fontBuilder import FontBuilder
from fontTools.pens.ttGlyphPen import TTGlyphPen


def cursive(path):
    glyphs = ['.notdef', 'space', 'beh', 'alef', 'seen', 'a', 'b', 'fatha']
    cmap = {0x20: 'space', 0x628: 'beh', 0x627: 'alef', 0x633: 'seen', 0x61: 'a', 0x62: 'b', 0x64E: 'fatha'}
    widths = {'.notdef': 500, 'space': 250, 'beh': 700, 'alef': 300, 'seen': 800, 'a': 500, 'b': 550, 'fatha': 0}
    fb = FontBuilder(1000, isTTF=True)
    fb.setupGlyphOrder(glyphs)
    fb.setupCharacterMap(cmap)
    def box(w):
        pen = TTGlyphPen(None)
        if w:
            pen.moveTo((50, 0)); pen.lineTo((50, 500)); pen.lineTo((w - 50, 500)); pen.lineTo((w - 50, 0)); pen.closePath()
        else:
            pen.moveTo((-200, 600)); pen.lineTo((-200, 700)); pen.lineTo((-50, 700)); pen.lineTo((-50, 600)); pen.closePath()
        return pen.glyph()
    fb.setupGlyf({g: box(widths[g] if g not in ('space',) else 0) if g != 'space' else TTGlyphPen(None).glyph() for g in glyphs})
    fb.setupHorizontalMetrics({g: (widths[g], 50) for g in glyphs})
    fb.setupHorizontalHeader(ascent=800, descent=-200)
    fb.setupNameTable({'familyName': 'PhpPdf Cursive Test', 'styleName': 'Regular'})
    fb.setupOS2(sTypoAscender=800, usWinAscent=800, usWinDescent=200)
    fb.setupPost()
    addOpenTypeFeaturesFromString(fb.font, '''
    languagesystem DFLT dflt;
    languagesystem arab dflt;
    languagesystem latn dflt;
    @MARKS = [fatha];
    table GDEF { GlyphClassDef [beh alef seen a b], , @MARKS, ; } GDEF;
    lookup CursRTL {
      lookupflag RightToLeft IgnoreMarks;
      pos cursive beh <anchor 0 0> <anchor 650 200>;
      pos cursive seen <anchor 30 -100> <anchor 760 300>;
      pos cursive alef <anchor 120 50> <anchor NULL>;
    } CursRTL;
    lookup CursLTR {
      pos cursive a <anchor 20 0> <anchor 480 120>;
      pos cursive b <anchor 40 60> <anchor 520 -40>;
    } CursLTR;
    lookup MarkRTL {
      markClass fatha <anchor -120 650> @TOP;
      pos base [beh alef seen] <anchor 350 600> mark @TOP;
    } MarkRTL;
    feature curs { script arab; lookup CursRTL; script latn; lookup CursLTR; } curs;
    feature mark { script arab; lookup MarkRTL; } mark;
    ''')
    fb.save(path)


def fallback(path):
    cmap = {0x20: 'space', 0x628: 'beh', 0x627: 'alef', 0x644: 'lam',
            0xFE8F: 'beh.isol', 0xFE90: 'beh.fina', 0xFE91: 'beh.init', 0xFE92: 'beh.medi',
            0xFE8D: 'alef.isol', 0xFE8E: 'alef.fina',
            0xFEDD: 'lam.isol', 0xFEDE: 'lam.fina', 0xFEDF: 'lam.init', 0xFEE0: 'lam.medi',
            0xFEFB: 'lam_alef.isol', 0xFEFC: 'lam_alef.fina'}
    glyphs = ['.notdef'] + sorted(set(cmap.values()), key=lambda g: list(cmap.values()).index(g))
    fb = FontBuilder(1000, isTTF=True)
    fb.setupGlyphOrder(glyphs)
    fb.setupCharacterMap(cmap)
    def box(w):
        pen = TTGlyphPen(None)
        if w > 0:
            pen.moveTo((50, 0)); pen.lineTo((50, 500)); pen.lineTo((w - 50, 500)); pen.lineTo((w - 50, 0)); pen.closePath()
        return pen.glyph()
    widths = {g: 300 + 37 * i for i, g in enumerate(glyphs)}
    widths['space'] = 250
    fb.setupGlyf({g: box(widths[g] if g != 'space' else 0) for g in glyphs})
    fb.setupHorizontalMetrics({g: (widths[g], 50) for g in glyphs})
    fb.setupHorizontalHeader(ascent=800, descent=-200)
    fb.setupNameTable({'familyName': 'PhpPdf Arabic Fallback Test', 'styleName': 'Regular'})
    fb.setupOS2(sTypoAscender=800, usWinAscent=800, usWinDescent=200)
    fb.setupPost()
    fb.save(path)


if __name__ == '__main__':
    cursive(sys.argv[1] + '/PhpPdfCursiveTest.ttf')
    fallback(sys.argv[1] + '/PhpPdfArabicFallbackTest.ttf')
