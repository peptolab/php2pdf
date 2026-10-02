# Development

```bash
composer install
composer check              # format check, lint, static analysis, unit tests
composer cs-fix             # mago format + mago lint --fix
composer test               # unit tests
composer test-integration   # needs qpdf and poppler-utils (pdftoppm, pdfinfo, pdftotext)
```

Code style and analysis use [Mago](https://mago.carthage.software/). `mago.toml` is a full copy
of the `php-db/phpdb-qa-tools` base configuration, so that tests are analysed too, with
thresholds suited to a drawing library. CI runs that package's QA workflow on PHP 8.3 to 8.5,
checking the format once for each version (`MAGO_PHP_VERSION=8.4 mago format --check` locally),
and the integration suite on Ubuntu and macOS with the PDF tooling installed. The code stays
valid PHP 8.3: write `(new Foo())->bar()`, not the 8.4 form without parentheses.

## Tests

- `test/Unit` (`#[Group('unit')]`, `composer test`): fast tests on in-memory documents that read
  no files, inspecting the generated syntax.
- `test/Integration` (`#[Group('integration')]`, `composer test-integration`): tests that read
  fonts, images and PDFs from `test/asset`, and end-to-end tests that write files to
  `test/output`, check them with `qpdf --check`, read them back with poppler, and compare renders
  pixel by pixel against `test/asset/baseline/*.png`.
- `test/TestAsset`: builders for font tables, images, PDFs and SVG, and the tools the tests share
  (`PdfTools`, `Visual`, `Golden`). `test/Trait`: shared set-up.

Both mirror `src/`. Run one suite with `vendor/bin/phpunit --testsuite unit` (or `integration`).

A missing baseline is written on the first run and the test is marked incomplete; run the suite
again to compare. Set `PHP2PDF_UPDATE_BASELINES=1` to regenerate baselines after an intentional
change. Comparison tolerates a small share of differing pixels so anti-aliasing differences
between poppler versions do not fail the build.

Fixtures live in `test/asset`: DejaVu, Source Serif 4 and Source Sans 3 fonts (see its README), image variants, a PDF corpus (classic, object
streams, linearized, incremental updates, broken xref, inherited attributes, ImageMagick and
cairo output, an encrypted file), SVG sheets and brochure copy.

## Architecture

The namespaces sit in layers, and each uses only the layers below it:

| Layer | Namespaces |
|-------|------------|
| 0 | `Exception` |
| 1 | `Core`: the object model, `Objects`, the writer, filters, output |
| 2 | `Geometry`, `Navigation` |
| 3 | `Color`, `Fit` |
| 4 | `Font` (with `Font\Bidi`) |
| 5 | `Canvas`, `Template` |
| 6 | `Image`, `Import` |
| 7 | `Flow`, `Text`, `Table` |
| 8 | `Svg` |
| 9 | `Document` |

A lower layer that needs something of the document declares a narrow interface for it, and the
document implements it: `Core\Objects` writes objects (fonts, images, templates and imported
pages hold the one they were made with, and refuse to be drawn into another document's canvas),
`Color\ColourPolicyInterface` checks colour against PDF/X and gives the document's colour
spaces, `Font\FontOptions` carries the font settings, `Text\TextContext` the fonts, styles
and inline objects text is set with, and `Svg\SvgDocumentInterface` what an SVG conversion
makes. The document makes textflows and tables (`textflow()`, `textflowBuilder()`, `table()`)
with its text context.

Three tests in `test/Integration/Architecture` keep the structure honest. `LayeringTest` holds the
namespaces to their layers, `ApiBoundaryTest` checks that every type says whether it is `@api` or
`@internal`, and `TestLayoutTest` checks that unit tests read no files and that every test
declares its suite's group. The classes excused from Mago's size and complexity limits are listed
per rule in `mago.toml`, as a ratchet: remove an entry when its class passes, and never add one
to make new code pass.
