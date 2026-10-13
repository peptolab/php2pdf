# Coming from PDFlib

Php2Pdf keeps the parts of PDFlib that design-led documents use: fit methods, Textflow, tables,
PDF import, embedded fonts and PDF/X. The model is objects instead of a handle and option lists:
a `Document` makes pages, fonts, images and flows, and each takes typed options.

| | PDFlib | Php2Pdf |
|---|---|---|
| State | One `PDFlib` object, handles as integers | Objects: `Document`, `Page`, `Canvas`, `Font`, `Image`, `Textflow`, `Table` |
| Options | Option list strings | Named arguments and small option classes |
| Units | Points | Points; `Unit::mm()`, `Box::fromMm()` at the boundary |
| Origin | Bottom-left (`topdown` optional) | Bottom-left; `addPage(topDown: true)` |
| Errors | Exceptions or `-1` with `get_errmsg()` | Exceptions implementing `ExceptionInterface`: `InvalidArgumentException`, `StateException`, and `PdfException` with its kinds (`ParseException`, `IoException`, `ConformanceException`, `LayoutException`) |

## Documents and pages

| PDFlib | Php2Pdf |
|---|---|
| `begin_document($file, '')` | `new Document($file)` or `new Document($file, new DocumentOptions(...))` |
| `begin_document('', '')` + `get_buffer()` | `new Document($out = new MemoryOutput())`, then `$out->contents()` |
| `begin_page_ext(595, 842, '')` | `$page = $doc->addPage(PageSize::A4)` or `addPage(new Dimensions(595, 842))` |
| `trimbox`, `bleedbox` page options | `$page->setTrimBox($box)`, `setBleedBox()`, `setCropBox()`, `setArtBox()` |
| `end_page_ext()` | Implicit: the next `addPage()` or `close()` finishes the page |
| `suspend_page` / `resume_page` | Finished pages cannot be revisited; draw page totals into a template from `$doc->onClose()` (see the cookbook) |
| `end_document('')` | `$doc->close()` |
| `set_info('Title', ...)` | `$doc->info()->setTitle(...)` |

## Fonts and text

| PDFlib | Php2Pdf |
|---|---|
| `load_font('Inter', 'unicode', 'embedding')` | `$doc->fonts()->load('Inter.ttf')` or `->find('Inter')` with a search path |
| `load_font('Helvetica', ...)` (core font) | `$doc->fonts()->standard(StandardFont::Helvetica)` or `->find('Helvetica')` |
| `fallbackfonts` | `new TextStyle(font: 'Inter', fallback: ['Helvetica', $symbols])` |
| `fit_textline($text, $x, $y, 'boxsize {..} position center fitmethod auto')` | `$page->fitTextline($text, $font, $size, $box, TextlineOptions::shrinkToFit(Position::center()))` |
| `show_xy` | `$canvas->drawText($text, $font, $size, $x, $y)` |
| `stringwidth` | `$font->width($text, $size)` |
| `kerning=true` | On by default; `TextStyle(kerning: false)` or `TextOptions(kerning: false)` turns it off |
| `features={liga}` | Standard ligatures on by default; `ligatures: false` on `TextStyle` or `TextOptions` turns them off |
| `features={tnum onum smcp}` | `features: ['tnum', 'onum', 'smcp']` on `TextStyle` or `TextOptions` |
| `charspacing`, `wordspacing`, `horizscaling`, `textrise` | `TextStyle(charSpacing:, wordSpacing:, horizontalScale:, rise:)` |

## Textflow

| PDFlib | Php2Pdf |
|---|---|
| `create_textflow($text, 'fontname=... fontsize=10 leading=120%')` | `$doc->textflow($text, new TextStyle(font: $font, size: 10, leading: Leading::percent(120)))` |
| Inline options `<fontname=... >` | Markup: `<p style="name">`, `<span style="name">`, `<b>`, `<i>`, `<sup>`; named styles with `$doc->styles()->define()` |
| `fit_textflow($tf, $llx, $lly, $urx, $ury, '')` returning `_boxfull`, `_stop` | `$page->fitTextflow($flow, $box)` returning `FlowResult::BoxFull`, `Done`, `BoxEmpty`, `NextPage` |
| `fitmethod=auto` / `minfontsize` | `new FlowOptions(shrinkToFit: true, shrinkLimit: 0.5)` |
| `verticalalign=center` | `new FlowOptions(verticalAlignment: VerticalAlignment::Center)` |
| `avoidemptybegin=true` | `new FlowOptions(avoidEmptyBegin: true)` |
| `info_textflow($tf, 'textendx')`, `'textendy'` | `$flow->lastFit()->textEnd` |
| `info_textflow($tf, 'firstlinedist')` etc. | `$flow->lastFit()->firstBaseline`, `lastBaseline`, `lineCount`, `height` |
| `hyphenchar`, `hortabmethod`, `ruler` | Soft hyphens and `Hyphenator` patterns; `tabs: [TabStop::decimal(120)]`, leaders with `TabStop::right(400, leader: '.')` |
| `<nextparagraph>`, `<nextline>` | Blank line or `</p>`, and `<br>` |
| Matchboxes for inline images | `<img src="name" height="9">` with `$doc->inlines()->define('name', $image)` |
| Matchboxes over text, `create_annotation` with `usematchbox` | `<span match="name">` and `lastFit()->matchboxes['name']`; links with `<a href="…">` |
| A flow over several frames | `$doc->flow($flow, [[$left, $right]], $makePage)`; `<columnbreak>` moves to the next frame |

## Tables

| PDFlib | Php2Pdf |
|---|---|
| `add_table_cell($tbl, $col, $row, $text, 'colwidth=..')` | `$doc->table([Column::mm(55), Column::auto()], ...)`, `$table->addRow([...])` |
| `header=1` | `$table->addHeaderRow([...])` |
| `footer=1` | `$table->addFooterRow([...])`, filled per box with `$table->onFooter(fn (FooterContext $box) => ...)` |
| `colspan`, `rowspan` | `Cell::text('...')->spanning(columns: 2, rows: 3)` |
| `fit_table` returning `_boxfull` | `$page->fitTable($table, $box)`, or `$doc->flow($table, $box, $makePage)` across pages |
| `info_table($tbl, 'height')` | `$table->lastFit()->height`, `rowEdges`, `columnEdges` |

## Images, templates and imported pages

| PDFlib | Php2Pdf |
|---|---|
| `load_image('auto', $file, '')` | `$doc->image($file)` (JPEG, JPEG 2000, PNG, TIFF natively) |
| `load_image` options `dpi`, `page`, `ignoreorientation`, `clippingpathname` | `new ImageOptions(dpi: 300, page: 2, orientation: false, clippingPath: 'Outline')` |
| `fit_image($img, $x, $y, 'boxsize {w h} fitmethod meet position center')` | `$page->place($image, $box, Fit::meet(Position::center()))` |
| `fitmethod` `nofit`, `clip`, `meet`, `slice`, `entire`, `auto` | `Fit::noFit()`, `clip()`, `meet()`, `slice()`, `entire()`, `auto()` |
| `position {50 50}`, `rotate 90` | `new Position(0.5, 0.5)` or `Position::center()`, `Fit::meet(rotate: 90)` |
| `begin_template_ext` / `end_template_ext` | `$template = $doc->template(new Dimensions(w, h))`, draw on `$template->canvas()` |
| `open_pdi_document`, `open_pdi_page` | `$doc->import('layout.ai')->page(1)` (`PageBox::Trim` for another box) |
| `fit_pdi_page` | `$page->place($importedPage, $box, Fit::entire())` |
| `pdi_info` geometry | `PdfFile::open($file)->page(1)->trimBox()` and so on |
| `open_pdi_document` with `password=`, `shrug=true` | `PdfFile::open($file, password: '...', ignorePermissions: true)` |
| Hidden layers in imported pages | Kept off; `$doc->import($file, HiddenLayers::Remove)` removes them |

## Graphics and colour

| PDFlib | Php2Pdf |
|---|---|
| `setcolor('fill', 'cmyk', c, m, y, k)` | `$canvas->setFillColor(Cmyk::fromPercent(...))` |
| `makespotcolor('Brand')` | `new Spot('Brand', Cmyk::fromPercent(...))` |
| Built-in spot colour libraries (`makespotcolor('PANTONE 300 C')`) | None bundled (licensed); `SpotLibrary::openAse('inks.ase')->get('PANTONE 300 C')` with your own swatches |
| `setcolor` with `lab` | `new Lab($l, $a, $b)` |
| DeviceN colour spaces (`create_devicen`) | `new DeviceN([DeviceN::black(), $spot])->withTints(0.4, 0.8)` |
| `load_iccprofile` + `iccbased` colour | `IccProfile::fromFile()`, `new IccColor($profile, 0.2, 0.4, 0.6)` |
| `shading('axial', ...)` + `shfill` | `new LinearGradient(...)` with `GradientStop`s, `$canvas->setFillGradient($gradient)` |
| `rect`, `moveto`, `lineto`, `curveto`, `fill`, `stroke`, `clip` | Same names on `Canvas`, chained |
| `save` / `restore` | `$canvas->save()` / `restore()` |

## Navigation and PDF/X

| PDFlib | Php2Pdf |
|---|---|
| `create_bookmark` | `$doc->outline()->add('Chapter', 5)` (a page number or a `Destination`) |
| `create_annotation(..., 'Link', 'action {activate ...}')` | `$page->addLink($box, 'https://...', tooltip: '...')` or `Destination::page(3)` |
| `add_nameddest` | `$doc->addDestination('name', Destination::top(5, 700))` |
| `pdfx=PDF/X-4` | `new DocumentOptions(pdfx: PdfX::X4)` |
| `pdfa=PDF/A-2b`, `pdfa=PDF/A-3b` | `new DocumentOptions(pdfa: PdfA::A2b)` |
| `load_asset("Attachment")`, `attachments=` | `$doc->attachFile($name, $contents, $mimeType, AttachmentRelationship::Data)` |
| `load_iccprofile(..., 'usage=outputintent')` | `$doc->setOutputIntent(IccProfile::fromFile(...), 'FOGRA39')` |

## Not there yet

Mark positioning, right-to-left and complex scripts, tagged PDF,
Forms and annotations other than links, and writing encrypted files: see
[limitations.md](limitations.md) and the issue tracker.
