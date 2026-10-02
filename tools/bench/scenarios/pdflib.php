<?php

/**
 * PDFlib implementations of the benchmark scenarios, mirroring php2pdf.php call for call.
 * Needs the PDFlib PHP extension (ext-pdflib, PDFlib 9.x or 10.x) and runs on PHP 8.1+.
 *
 * Without a licence key PDFlib runs in evaluation mode and stamps every page. Set PDFLIB_LICENCE
 * to use a key. Compare file sizes with care in evaluation mode; blank-500 measures the stamp.
 */

declare(strict_types=1);

require_once __DIR__ . '/common.php';

if (! class_exists('PDFlib')) {
    fwrite(STDERR, "The PDFlib extension is not loaded in this PHP (" . PHP_BINARY . ").\n");
    exit(1);
}

/** Opens a PDFlib document with the fixture fonts registered. */
function bench_pdflib(string $out, string $fx): PDFlib
{
    $p = new PDFlib();
    $p->set_option('errorpolicy=exception');
    $p->set_option('stringformat=utf8');
    $licence = getenv('PDFLIB_LICENCE') ?: getenv('PDFLIB_LICENSE');
    if ($licence) {
        $p->set_option("license={$licence}");
    }
    $p->set_option("SearchPath={{{$fx}}}");
    $p->set_option('FontOutline={DejaVuSans=DejaVuSans.ttf}');
    $p->set_option('FontOutline={DejaVuSans-Bold=DejaVuSans-Bold.ttf}');
    $p->begin_document($out, '');
    $p->set_info('Creator', 'php2pdf benchmark');

    return $p;
}

function bench_begin_page(PDFlib $p): void
{
    $p->begin_page_ext(BENCH_A4[0], BENCH_A4[1], '');
}

/** PDFlib boxes are corners, not x/y/width/height. */
function bench_corners(array $box): array
{
    return [$box[0], $box[1], $box[0] + $box[2], $box[1] + $box[3]];
}

function bench_textline(PDFlib $p, string $text, int $font, float $size, array $box): void
{
    $p->fit_textline($text, $box[0], $box[1], "font={$font} fontsize={$size} boxsize={{$box[2]} {$box[3]}} position={left center}");
}

/**
 * Two columns of body copy per page with a headline. $extra adds textflow options (alignment);
 * $body turns the fixture text into the text to set.
 */
$text = static function (int $pages, string $extra = '', ?Closure $body = null): Closure {
    return static function (string $out, string $fx) use ($pages, $extra, $body): int {
        $p    = bench_pdflib($out, $fx);
        $sans = $p->load_font('DejaVuSans', 'unicode', 'embedding');
        $bold = $p->load_font('DejaVuSans-Bold', 'unicode', 'embedding');
        $copy = (string) file_get_contents("{$fx}/body.txt");
        $body = null === $body ? $copy : $body($copy);
        $opts = trim("font={$sans} fontsize=9.5 leading=13 begoptlistchar=none {$extra}");
        $flow = $p->create_textflow($body, $opts);
        $done = false;
        for ($n = 1; $n <= $pages; ++$n) {
            if ($done) {
                $p->delete_textflow($flow);
                $flow = $p->create_textflow($body, $opts);
                $done = false;
            }
            bench_begin_page($p);
            bench_textline($p, "Page {$n}", $bold, 14.0, BENCH_HEADLINE);
            foreach ([BENCH_COLUMN_1, BENCH_COLUMN_2] as $column) {
                if (! $done) {
                    [$llx, $lly, $urx, $ury] = bench_corners($column);
                    $done = '_stop' === $p->fit_textflow($flow, $llx, $lly, $urx, $ury, '');
                }
            }
            $p->end_page_ext('');
        }
        $p->delete_textflow($flow);
        $p->end_document('');

        return $pages;
    };
};

return [
    'hello' => static function (string $out, string $fx): int {
        $p    = bench_pdflib($out, $fx);
        $helv = $p->load_font('Helvetica', 'winansi', '');
        bench_begin_page($p);
        bench_textline($p, 'Hello, world', $helv, 24.0, [72.0, 700.0, 400.0, 40.0]);
        $p->end_page_ext('');
        $p->end_document('');

        return 1;
    },

    'blank-500' => static function (string $out, string $fx): int {
        $p = bench_pdflib($out, $fx);
        for ($n = 0; $n < 500; ++$n) {
            bench_begin_page($p);
            $p->end_page_ext('');
        }
        $p->end_document('');

        return 500;
    },

    'text-100'  => $text(100),
    'text-500'  => $text(500),
    'text-2000' => $text(2000),

    'justify-100' => $text(100, 'alignment=justify'),

    'paragraphs-long-50' => $text(50, 'alignment=justify', bench_long_paragraphs(...)),

    'documents-20' => static function (string $out, string $fx) use ($text): int {
        $pages = 0;
        for ($d = 0; $d < 20; ++$d) {
            $pages += $text(5)($out, $fx);
        }

        return $pages;
    },

    'table-2000' => static function (string $out, string $fx): int {
        $p     = bench_pdflib($out, $fx);
        $sans  = $p->load_font('DejaVuSans', 'unicode', 'embedding');
        $line  = "fittextline={font={$sans} fontsize=9 position={left top}} margin=2";
        $right = "fittextline={font={$sans} fontsize=9 position={right top}} margin=2";
        $cols  = BENCH_TABLE_COLS;
        $tbl   = 0;
        $tbl   = $p->add_table_cell($tbl, 1, 1, 'Reference', "{$line} colwidth={$cols[0]}");
        $tbl   = $p->add_table_cell($tbl, 2, 1, 'Description', "{$line} colwidth={$cols[1]}");
        $tbl   = $p->add_table_cell($tbl, 3, 1, 'Amount', "{$right} colwidth={$cols[2]}");
        $row   = 1;
        foreach (bench_table_rows() as [$ref, $desc, $amount]) {
            ++$row;
            $tbl = $p->add_table_cell($tbl, 1, $row, $ref, $line);
            $tf  = $p->create_textflow($desc, "font={$sans} fontsize=9 leading=11.5 begoptlistchar=none");
            $tbl = $p->add_table_cell($tbl, 2, $row, '', "textflow={$tf} margin=2");
            $tbl = $p->add_table_cell($tbl, 3, $row, $amount, $right);
        }
        [$llx, $lly, $urx, $ury] = bench_corners(BENCH_TABLE_BOX);
        $pages = 0;
        do {
            bench_begin_page($p);
            ++$pages;
            $result = $p->fit_table($tbl, $llx, $lly, $urx, $ury, 'header=1');
            $p->end_page_ext('');
        } while ('_boxfull' === $result);
        if ('_stop' !== $result) {
            throw new RuntimeException("fit_table returned {$result}");
        }
        $p->delete_table($tbl, '');
        $p->end_document('');

        return $pages;
    },

    'images-reuse-100' => static function (string $out, string $fx): int {
        $p   = bench_pdflib($out, $fx);
        $jpg = $p->load_image('auto', "{$fx}/photo.jpg", '');
        $png = $p->load_image('auto', "{$fx}/photo.png", '');
        for ($n = 0; $n < 100; ++$n) {
            bench_begin_page($p);
            foreach ([[$jpg, BENCH_IMAGE_TOP], [$png, BENCH_IMAGE_BOTTOM]] as [$image, $box]) {
                $p->fit_image($image, $box[0], $box[1], "boxsize={{$box[2]} {$box[3]}} fitmethod=meet");
            }
            $p->end_page_ext('');
        }
        $p->close_image($jpg);
        $p->close_image($png);
        $p->end_document('');

        return 100;
    },

    'images-distinct-40' => static function (string $out, string $fx): int {
        $p   = bench_pdflib($out, $fx);
        $box = BENCH_IMAGE_FULL;
        foreach (bench_distinct_images() as $name) {
            $image = $p->load_image('auto', "{$fx}/{$name}", '');
            bench_begin_page($p);
            $p->fit_image($image, $box[0], $box[1], "boxsize={{$box[2]} {$box[3]}} fitmethod=meet");
            $p->end_page_ext('');
            $p->close_image($image);
        }
        $p->end_document('');

        return 40;
    },

    'import-500' => static function (string $out, string $fx): int {
        $p    = bench_pdflib($out, $fx);
        $sans = $p->load_font('DejaVuSans', 'unicode', 'embedding');
        $pdi  = $p->open_pdi_document("{$fx}/template.pdf", '');
        $tpl  = $p->open_pdi_page($pdi, 1, '');
        for ($n = 1; $n <= 500; ++$n) {
            bench_begin_page($p);
            $p->fit_pdi_page($tpl, 0, 0, 'boxsize={' . BENCH_A4[0] . ' ' . BENCH_A4[1] . '} fitmethod=entire');
            bench_textline($p, "Page {$n}", $sans, 12.0, BENCH_FOOTER);
            $p->end_page_ext('');
        }
        $p->close_pdi_page($tpl);
        $p->close_pdi_document($pdi);
        $p->end_document('');

        return 500;
    },

    'svg-100' => static function (string $out, string $fx): int {
        $p   = bench_pdflib($out, $fx);
        $art = $p->load_graphics('auto', "{$fx}/art.svg", '');
        for ($n = 0; $n < 10; ++$n) {
            bench_begin_page($p);
            foreach (bench_svg_grid() as $cell) {
                $p->fit_graphics($art, $cell[0], $cell[1], "boxsize={{$cell[2]} {$cell[3]}} fitmethod=meet");
            }
            $p->end_page_ext('');
        }
        $p->close_graphics($art);
        $p->end_document('');

        return 10;
    },
];
