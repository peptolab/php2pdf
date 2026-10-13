# Benchmarks: php2pdf and PDFlib

The same scenarios, with the same fixtures and geometry, written once for php2pdf
(`scenarios/php2pdf.php`) and once for PDFlib (`scenarios/pdflib.php`). Each run is a fresh PHP
process; the runner reports the median of the timed runs after one discarded warm-up run.

| Scenario | What it does |
|----------|--------------|
| `hello` | One page, one line in Helvetica (start-up cost) |
| `blank-500` | 500 empty A4 pages (the per-page floor; shows PDFlib's evaluation stamp cost) |
| `text-100` / `text-500` / `text-2000` | Two-column flowed text in embedded, subset DejaVu Sans, a bold headline per page. Memory should stay flat as pages grow |
| `justify-100` | `text-100` justified: php2pdf's paragraph composer against PDFlib's justification |
| `hyphenate-100` | `justify-100` with English hyphenation (php2pdf only; needs the patterns below) |
| `paragraphs-long-50` | Justified copy in paragraphs of 400 words, about 2,500 characters each: long stretches for shaping and line breaking |
| `documents-20` | Twenty five-page `text` documents, one after another in the same process. RSS should not grow with the count; growth means a cache that outlives its document |
| `table-2000` | A 2,000-row, three-column table with a repeating header and wrapping descriptions, flowed across as many pages as it needs |
| `images-reuse-100` | 100 pages each placing the same 3000×2000 JPEG and 2000×1333 PNG |
| `images-distinct-40` | 40 pages, each with a different image (20 JPEGs at 1600×1200, 20 PNGs at 1200×900) |
| `import-500` | Page 1 of a third-party (cairo) PDF placed as a background on 500 pages, with a footer line |
| `svg-100` | One SVG (gradients, clips, `<use>`) placed 100 times, 10 per page |
| `six-fonts-10` | Ten pages, each with six boxes of body copy in a different font: DejaVu Sans, Source Sans 3 (CFF) and Source Serif 4, regular and bold. Subsetting several fonts used lightly |
| `cjk-10` | Ten pages of two columns of Chinese, 3,500 different ideographs, in a CJK font of tens of thousands of glyphs (skipped without one; see below) |

## Running

Each run is a child process with `XDEBUG_MODE=off`: Xdebug's coverage mode, if loaded, would
make php2pdf about four times slower. The header says whether Xdebug is loaded.

`fixtures.php` copies English hunspell hyphenation patterns (`hyph_en_US.dic`) from the usual
places, or from `BENCH_HYPHEN`. Without them `hyphenate-100` is skipped. It also copies a large CJK font for
`cjk-10` (face 0 of a collection), from `BENCH_CJK` or Noto Sans CJK on Linux
(`fonts-noto-cjk`) or Arial Unicode on macOS; without one `cjk-10` is skipped.

```sh
php tools/bench/fixtures.php                       # once; needs ext-gd

php8.3 tools/bench/run.php --lib=php2pdf --json=php2pdf.json
php8.1 tools/bench/run.php --lib=pdflib --json=pdflib.json   # whichever PHP has ext-pdflib

php tools/bench/compare.php php2pdf.json pdflib.json
```

Options: `--only=text-500,table-2000` runs some scenarios, `--runs=5` changes the number of timed
runs (default 3), `--warmup=0` skips the warm-up, and `--keep` leaves the PDFs in
`tools/bench/out/` so you can open and compare them.

The PDFlib side runs on PHP 8.1 or later and needs nothing from Composer. php2pdf needs PHP 8.3
and `composer install`. Running them on different PHP versions is fair enough: PDFlib does
nearly all its work in C, so the interpreter version barely affects it. The header of each run
prints the PHP and library versions so the results stay labelled.

## Comparing commits

Results for php2pdf at a commit are kept in `results/php2pdf-<commit>.json`, so a change can be
compared with the commit before it:

```sh
php tools/bench/run.php --lib=php2pdf --json=tools/bench/results/php2pdf-$(git rev-parse --short HEAD).json
php tools/bench/compare.php tools/bench/results/php2pdf-<new>.json tools/bench/results/php2pdf-<old>.json
```

## Licence and evaluation mode

Without a licence key PDFlib runs in evaluation mode with every feature available, but it
stamps each page. That stamp adds a little drawing to every page, so:

- per-page time and memory are still a fair comparison;
- file sizes are inflated, so treat PDFlib's sizes as approximate;
- `blank-500` shows what the stamp costs on its own (pages with nothing else on them).

To run licensed, set `PDFLIB_LICENCE` (or `PDFLIB_LICENSE`) in the environment; the scripts pass it
to `set_option("license=…")`.

## Reading the numbers

- **median s / ms/page**: wall-clock time from the first library call until the file is closed.
- **RSS MiB / RSS Δ MiB**: the process's peak resident memory, and how much it grew during the
  scenario. PDFlib allocates in C, outside PHP's memory manager, so only RSS sees its memory.
  Compare the libraries on **RSS Δ**.
- **Zend MiB**: PHP's own heap peak (`memory_get_peak_usage(true)`). Meaningful for php2pdf, near
  zero for PDFlib.
- **size KiB**: the output file.

Opcache and JIT are reported in the header and are off on the CLI by default. Settings passed
with `-d` reach the child processes, so a JIT run is
`php -d opcache.enable_cli=1 -d opcache.jit_buffer_size=64M -d opcache.jit=tracing tools/bench/run.php --lib=php2pdf`.

## Keeping the scenarios equal

Both files use the boxes and data in `scenarios/common.php`. The libraries differ in a few
places, by design:

- In `table-2000`, PDFlib's reference and amount cells use `fittextline` (single lines) and the
  description cell a Textflow; php2pdf sets every cell as text that can wrap. Each is how you
  would normally write that table in that library.
- PDFlib textflows are created with `begoptlistchar=none` so the body copy is never read as
  inline option lists.
