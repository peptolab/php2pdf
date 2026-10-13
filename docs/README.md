# Php2Pdf documentation

Php2Pdf is a pure-PHP library for precision PDF layout: embedded fonts, templates from PDF, AI
and SVG, images, and text and tables that flow through chained boxes across pages. It needs
PHP 8.3 or later with `ext-zlib` and `ext-mbstring`, and nothing else at runtime.

| Guide | What it covers |
|-------|----------------|
| [Getting started](getting-started.md) | Installation, a first document, units, coordinate origins, validating output |
| [Coming from PDFlib](coming-from-pdflib.md) | PDFlib functions and options and their Php2Pdf equivalents |
| [Documents and pages](document.md) | `Document`, `DocumentOptions`, pages, page boxes, metadata, output targets, determinism, streaming |
| [Canvas and colour](canvas.md) | Paths, transforms, graphics state, clipping, colour spaces, spot colours, gradients, opacity, blend modes |
| [Fonts and text](fonts-and-text.md) | The font registry, families and metrics, drawing text, `fitTextline()` |
| [Images](images.md) | JPEG, JPEG 2000, PNG and TIFF natively, other formats through ext-imagick or ext-gd, resolution, orientation, clipping paths, transparency |
| [The fit engine](fit-engine.md) | Fit methods, positions, rotation and scale, what `Placement` tells you |
| [Templates and PDF import](templates-and-import.md) | Reusable artwork, importing pages from PDF and AI files, the read API |
| [SVG](svg.md) | The native converter, what it supports, the rsvg-convert adapter |
| [Textflow](textflow.md) | Styles, markup, the builder, box chaining, results and queries, copy fitting, hyphenation |
| [Tables](tables.md) | Columns, rows, cells, styles, spans, header rows, groups, splitting, `Document::flow()` |
| [Links, bookmarks, PDF/X-4 and PDF/A](navigation-and-pdfx.md) | Link annotations, destinations, the outline, print production and archival output, attachments and Factur-X invoices |
| [Cookbook](cookbook.md) | An invoice ported from PDFlib, a two-column brochure, a report with a repeating table |
| [Limitations](limitations.md) | What is not supported, and what to expect at the edges |
| [Development](development.md) | Code style with Mago, running the tests, visual baselines |
