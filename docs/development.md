# Development

```bash
composer install
composer check              # format check, lint, static analysis, unit tests
composer cs-fix             # mago format + mago lint --fix
composer test               # unit tests
composer test-integration   # needs qpdf and poppler-utils (pdftoppm, pdfinfo, pdftotext)
```

Code style and analysis use [Mago](https://mago.carthage.software/) through
`php-db/phpdb-qa-tools`; `mago.toml` extends its configuration with thresholds suited to a
drawing library. CI runs the QA workflow of that package on PHP 8.3 to 8.5 and the integration
suite on Ubuntu with the PDF tooling installed.

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

Two tests in `test/Integration/Architecture` keep the structure honest. `LayeringTest` holds the
namespaces to their layers (each may use only the layers below it); `TestLayoutTest` checks that
unit tests read no files and that every test declares its suite's group. Uses that break the
layering today are listed in `LayeringTest::RATCHET` with the work that removes them, and the
classes excused from Mago's size and complexity limits are listed per rule in `mago.toml`. Both
lists only shrink: remove an entry when its class passes, and never add one to make new code
pass.

A missing baseline is written on the first run and the test is marked incomplete; run the suite
again to compare. Set `PHP2PDF_UPDATE_BASELINES=1` to regenerate baselines after an intentional
change. Comparison tolerates a small share of differing pixels so anti-aliasing differences
between poppler versions do not fail the build.

Fixtures live in `test/asset`: DejaVu, Source Serif 4 and Source Sans 3 fonts (see its README), image variants, a PDF corpus (classic, object
streams, linearized, incremental updates, broken xref, inherited attributes, ImageMagick and
cairo output, an encrypted file), SVG sheets and brochure copy.
