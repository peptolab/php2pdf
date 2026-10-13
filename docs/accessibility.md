# Tagged PDF and PDF/UA

A tagged PDF carries a logical structure beside its drawing: headings, paragraphs, tables,
figures and links in reading order, which screen readers read, viewers reflow, and text
extraction follows. Everything that is not content (rules, backgrounds, repeated table headers,
decoration) is marked as an artifact, which assistive technology skips.

```php
$doc = new Document('report.pdf', new DocumentOptions(
    accessibility: new Accessibility('en-GB', pdfUa: true),
));
$doc->info()->setTitle('Annual report');
```

`Accessibility` turns tagging on. `language` is the document's natural language, a BCP 47 tag
such as `en-GB`. `pdfUa: true` writes PDF/UA-1 (ISO 14289-1) as well, and the document then:

- needs a title and a language, checked when it closes;
- asks viewers to show the title in place of the file name;
- embeds every font and refuses characters a font has no glyph for, as PDF/A does;
- describes every link: its tooltip, or its address;
- writes PDF 1.7 and declares the standard in the XMP metadata (with its extension schema under
  PDF/A, which can be set alongside).

Check files with [veraPDF](https://verapdf.org) (`verapdf -f ua1`), which the library's own
PDF/UA tests pass, or the PDF Accessibility Checker (PAC).

## What is tagged

On a tagged page everything drawn is either part of an element or an artifact. Without asking,
the library tags:

| Content | Tagged as |
|---|---|
| A textflow's paragraphs | `P`, or the role of their style (`TextStyle(role: StructureRole::H1)`) |
| Linked text in a textflow | `Link`, under its paragraph, with its link annotations |
| A table | `Table`, its rows `TR`, header cells `TH` (heading their column) and other cells `TD`, with their spans |
| Artwork placed with alternate text | `Figure`: `$page->place($image, $box, alt: 'A photograph')` |
| A link added to a page | `Link`, holding its annotation |
| Anything else | An artifact |

A paragraph or a link broken across lines, columns or pages stays one element, and so does a
table row broken across boxes. A table's header rows are tagged where they first appear; the
copies repeated at the top of later boxes are artifacts. Footer rows are tagged once, in the box
where the table ends, so they are read after the body. Underlines, strikeouts, cell backgrounds,
borders and artwork set in text are artifacts.

Artwork placed without alternate text is part of whatever is being tagged when it is drawn, so
an image inside a tagged element belongs to it; drawn on its own it is an artifact.

## Tagging content yourself

`Canvas::tag()` draws content as an element of any `StructureRole`, under the element being
drawn, if any:

```php
$canvas->tag(StructureRole::H1, function (Canvas $canvas) use ($font): void {
    $canvas->drawText('Annual report', $font, 24.0, 50.0, 780.0);
});
$canvas->tag(StructureRole::Section, function (Canvas $canvas) use ($page, $flow): void {
    $page->fitTextflow($flow, new Box(50.0, 100.0, 495.0, 600.0));
});
$canvas->tag(StructureRole::Figure, function (Canvas $canvas) use ($chart): void {
    $canvas->path($chart)->fill();
}, 'Sales by quarter');
```

Textflows and tables drawn inside a tagged element are tagged under it, as the section above
holds the flow's paragraphs. Elements are read in the order their content is drawn.

On an untagged document, and on a template's canvas, `tag()` simply draws: templates are artwork,
tagged where they are placed.

## Imported pages

A page imported from a tagged PDF brings its structure where it is first placed on a page: the
elements its content belongs to are copied, in their order, into a `Sect` under the element
being drawn, with their alternate text, language and attributes, and types mapped through the
source's role map to the standard ones. Its flattened annotations are artifacts. A page can
carry its structure only once, so its other placements, and any in a template, are artifacts.
Placed with alternate text, or inside a figure, the page is a figure and its own structure is
left out. A page of an untagged PDF is part of the element being drawn, or an artifact.

## What it does not do

- Lists are not tagged automatically: tag them with `StructureRole::List`, `ListItem`, `Label`
  and `ListBody`.
- Forms, and annotations other than links, are not written at all, so need no tagging.
- PDF/UA asks for meaningful reading order and alternate text, which only the author can give;
  the validators check what can be checked.
