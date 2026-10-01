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

- `test/unit`: fast tests on in-memory documents, inspecting the generated syntax.
- `test/integration`: end-to-end tests that write files to `test/output`, check them with
  `qpdf --check`, read them back with poppler, and compare renders pixel by pixel against
  `test/asset/baseline/*.png`.

A missing baseline is written on the first run and the test is marked incomplete; run the suite
again to compare. Set `PHP2PDF_UPDATE_BASELINES=1` to regenerate baselines after an intentional
change. Comparison tolerates a small share of differing pixels so anti-aliasing differences
between poppler versions do not fail the build.

Fixtures live in `test/asset`: DejaVu, Source Serif 4 and Source Sans 3 fonts (see its README), image variants, a PDF corpus (classic, object
streams, linearized, incremental updates, broken xref, inherited attributes, ImageMagick and
cairo output, an encrypted file), SVG sheets and brochure copy.
