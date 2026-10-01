"""
Builds test/asset/fonts/PhpPdfCidTest.otf, a small CID-keyed CFF OpenType font for the CFF
subsetter tests, from Source Sans 3 Regular (SIL OFL 1.1).

Usage: python3 tools/make-cid-test-font.py SourceSans3-Regular.otf test/asset/fonts/PhpPdfCidTest.otf

Needs fontTools. The font is reduced to Basic Latin, converted to CID-keyed CFF with two Font
DICTs (upper-case letters use the second), and its CIDs are spread out (CID = 2 x glyph id) so
that CIDs and glyph ids differ. As a modified version it is renamed, since "Source" is a
Reserved Font Name under the OFL.
"""

import sys

from fontTools import subset
from fontTools.cffLib import FDArrayIndex, FDSelect, FontDict
from fontTools.ttLib import TTFont

source, target = sys.argv[1], sys.argv[2]

font = TTFont(source)
options = subset.Options()
options.desubroutinize = False
options.name_IDs = ["*"]
options.notdef_outline = True
subsetter = subset.Subsetter(options)
subsetter.populate(unicodes=range(0x20, 0x7F))
subsetter.subset(font)



cff = font["CFF "].cff
top = cff.topDictIndex[0]
order = font.getGlyphOrder()
private = top.Private

fdArray = FDArrayIndex()
fdArray.strings = None
fdArray.GlobalSubrs = top.GlobalSubrs
for _ in range(2):
    fd = FontDict()
    fd.setCFF2(False)
    fd.Private = private
    fd.FontMatrix = [0.001, 0, 0, 0.001, 0, 0]
    fdArray.append(fd)
upper = {font.getBestCmap().get(c) for c in range(ord("A"), ord("Z") + 1)}
fdSelect = FDSelect()
fdSelect.gidArray = [1 if name in upper else 0 for name in order]

top.ROS = ("Adobe", "Identity", 0)
top.CIDCount = 2 * len(order)
top.FontMatrix = [1, 0, 0, 1, 0, 0]
top.FDArray = fdArray
top.FDSelect = fdSelect
del top.rawDict["Private"]
del top.Private
charStrings = top.CharStrings
charStrings.fdArray = fdArray
charStrings.fdSelect = fdSelect
for name in charStrings.keys():
    charStrings[name].private = fdArray[fdSelect[order.index(name)]].Private

renamed = {name: ".notdef" if gid == 0 else "cid" + str(gid * 2).zfill(5) for gid, name in enumerate(order)}
top.charset = [renamed[name] for name in order]
charStrings.charStrings = {renamed[n]: v for n, v in charStrings.charStrings.items()}
font.setGlyphOrder(top.charset)
font["hmtx"].metrics = {renamed[n]: m for n, m in font["hmtx"].metrics.items()}
for sub in font["cmap"].tables:
    sub.cmap = {c: renamed[n] for c, n in sub.cmap.items()}
for tag in ("GPOS", "GSUB", "GDEF", "BASE"):
    if tag in font:
        del font[tag]
font["post"].formatType = 3.0

cff.fontNames = ["PhpPdfCidTest-Regular"]
names = font["name"]
for record in list(names.names):
    if record.nameID in (1, 16):
        names.setName("Php2Pdf CID Test", record.nameID, record.platformID, record.platEncID, record.langID)
    elif record.nameID in (3, 4):
        names.setName("Php2Pdf CID Test Regular", record.nameID, record.platformID, record.platEncID, record.langID)
    elif record.nameID == 6:
        names.setName("PhpPdfCidTest-Regular", record.nameID, record.platformID, record.platEncID, record.langID)

font.save(target)
