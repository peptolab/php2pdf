<?php

/**
 * php2pdf implementations of the benchmark scenarios. Each closure writes one PDF to $out from the
 * fixtures in $fx and returns the number of pages written. Keep in step with pdflib.php.
 */

declare(strict_types=1);

use Php2Pdf\Core\FixedClock;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\Page;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Fit\Position;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Svg\NativeConverter;
use Php2Pdf\Table\CellStyle;
use Php2Pdf\Table\Column;
use Php2Pdf\Table\Padding;
use Php2Pdf\Table\Table;
use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\Leading;
use Php2Pdf\Text\Hyphenation\Hyphenator;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\TextFormat;
use Php2Pdf\Canvas\TextlineOptions;

require_once dirname(__DIR__, 3) . '/vendor/autoload.php';

require_once __DIR__ . '/common.php';

$newDoc = static fn (string $out): Document => new Document($out, new DocumentOptions(clock: new FixedClock('2026-01-01T12:00:00Z')));

/**
 * Two columns of body copy per page with a headline, for $pages pages. $style adds to the body
 * style (alignment, hyphenation); $body turns the fixture text into the text to set.
 */
$text = static function (int $pages, ?TextStyle $style = null, ?Closure $body = null) use ($newDoc): Closure {
    return static function (string $out, string $fx) use ($pages, $style, $body, $newDoc): int {
        if (true === $style?->hyphenate && ! is_file("{$fx}/hyph-en.dic")) {
            return 0;
        }
        $doc  = $newDoc($out);
        $sans = $doc->fonts()->load("{$fx}/DejaVuSans.ttf");
        $bold = $doc->fonts()->load("{$fx}/DejaVuSans-Bold.ttf");
        $doc->styles()->define('body', new TextStyle(font: $sans, size: 9.5, leading: Leading::pt(13.0)));
        if (null !== $style) {
            $doc->styles()->define('set', $style, basedOn: 'body');
        }
        $copy        = (string) file_get_contents("{$fx}/body.txt");
        $hyphenators = true === $style?->hyphenate ? ['en' => Hyphenator::fromFile("{$fx}/hyph-en.dic", 'en')] : [];
        $flow        = $doc->textflow(null === $body ? $copy : $body($copy), null === $style ? 'body' : 'set', TextFormat::Plain, $hyphenators);
        for ($n = 1; $n <= $pages; ++$n) {
            if ($flow->isDone()) {
                $flow->reset();
            }
            $page = $doc->addPage(PageSize::A4);
            $page->fitTextline("Page {$n}", $bold, 14.0, new Box(...BENCH_HEADLINE), TextlineOptions::at(Position::centerLeft()));
            $page->fitTextflow($flow, new Box(...BENCH_COLUMN_1));
            $page->fitTextflow($flow, new Box(...BENCH_COLUMN_2));
        }
        $doc->close();

        return $pages;
    };
};

$justified = new TextStyle(alignment: Alignment::Justify);

return [
    'hello' => static function (string $out, string $fx) use ($newDoc): int {
        $doc  = $newDoc($out);
        $helv = $doc->fonts()->find('Helvetica');
        $doc->addPage(PageSize::A4)->fitTextline('Hello, world', $helv, 24.0, new Box(72.0, 700.0, 400.0, 40.0), TextlineOptions::at(Position::centerLeft()));
        $doc->close();

        return 1;
    },

    'blank-500' => static function (string $out, string $fx) use ($newDoc): int {
        $doc = $newDoc($out);
        for ($n = 0; $n < 500; ++$n) {
            $doc->addPage(PageSize::A4);
        }
        $doc->close();

        return 500;
    },

    'text-100'  => $text(100),
    'text-500'  => $text(500),
    'text-2000' => $text(2000),

    'justify-100'   => $text(100, $justified),
    'hyphenate-100' => $text(100, new TextStyle(alignment: Alignment::Justify, hyphenate: true, language: 'en')),

    'paragraphs-long-50' => $text(50, $justified, bench_long_paragraphs(...)),

    'documents-20' => static function (string $out, string $fx) use ($text): int {
        $pages = 0;
        for ($d = 0; $d < 20; ++$d) {
            $pages += $text(5)($out, $fx);
        }

        return $pages;
    },

    'table-2000' => static function (string $out, string $fx) use ($newDoc): int {
        $doc  = $newDoc($out);
        $sans = $doc->fonts()->load("{$fx}/DejaVuSans.ttf");
        $doc->styles()->define('body', new TextStyle(font: $sans, size: 9.0, leading: Leading::pt(11.5)));
        $table = $doc->table(
            [Column::pt(BENCH_TABLE_COLS[0]), Column::pt(BENCH_TABLE_COLS[1]), Column::pt(BENCH_TABLE_COLS[2], new CellStyle(alignment: Alignment::Right))],
            new CellStyle(padding: Padding::all(2.0)),
            'body',
        );
        $table->addHeaderRow(['Reference', 'Description', 'Amount']);
        foreach (bench_table_rows() as [$ref, $desc, $amount]) {
            $table->addRow([$ref, $desc, $amount]);
        }

        $pages = $doc->flow($table, new Box(...BENCH_TABLE_BOX), static fn (Document $d, int $n): Page => $d->addPage(PageSize::A4));
        $doc->close();

        return $pages;
    },

    'images-reuse-100' => static function (string $out, string $fx) use ($newDoc): int {
        $doc = $newDoc($out);
        $jpg = $doc->image("{$fx}/photo.jpg");
        $png = $doc->image("{$fx}/photo.png");
        for ($n = 0; $n < 100; ++$n) {
            $page = $doc->addPage(PageSize::A4);
            $page->place($jpg, new Box(...BENCH_IMAGE_TOP), Fit::meet());
            $page->place($png, new Box(...BENCH_IMAGE_BOTTOM), Fit::meet());
        }
        $doc->close();

        return 100;
    },

    'images-distinct-40' => static function (string $out, string $fx) use ($newDoc): int {
        $doc = $newDoc($out);
        foreach (bench_distinct_images() as $name) {
            $doc->addPage(PageSize::A4)->place($doc->image("{$fx}/{$name}"), new Box(...BENCH_IMAGE_FULL), Fit::meet());
        }
        $doc->close();

        return 40;
    },

    'import-500' => static function (string $out, string $fx) use ($newDoc): int {
        $doc  = $newDoc($out);
        $sans = $doc->fonts()->load("{$fx}/DejaVuSans.ttf");
        $tpl  = $doc->import("{$fx}/template.pdf")->page(1);
        for ($n = 1; $n <= 500; ++$n) {
            $page = $doc->addPage(PageSize::A4);
            $page->place($tpl, $page->mediaBox(), Fit::entire());
            $page->fitTextline("Page {$n}", $sans, 12.0, new Box(...BENCH_FOOTER), TextlineOptions::at(Position::centerLeft()));
        }
        $doc->close();

        return 500;
    },

    'six-fonts-10' => static function (string $out, string $fx) use ($newDoc): int {
        $doc   = $newDoc($out);
        $fonts = array_map(static fn (string $file): mixed => $doc->fonts()->load("{$fx}/{$file}"), array_values(BENCH_SIX_FONTS));
        $copy  = bench_excerpts((string) file_get_contents("{$fx}/body.txt"));
        for ($n = 0; $n < 10; ++$n) {
            $page = $doc->addPage(PageSize::A4);
            foreach (bench_six_boxes() as $i => $box) {
                $text = $copy[($n * 6 + $i) % count($copy)];
                $flow = $doc->textflow($text, new TextStyle(font: $fonts[$i], size: 9.5, leading: Leading::pt(13.0)), TextFormat::Plain);
                $page->fitTextflow($flow, new Box(...$box));
            }
        }
        $doc->close();

        return 10;
    },

    'cjk-10' => static function (string $out, string $fx) use ($newDoc): int {
        $font = bench_cjk_font($fx);
        if (null === $font) {
            return 0;
        }
        $doc  = $newDoc($out);
        $cjk  = $doc->fonts()->load($font);
        $bold = $doc->fonts()->load("{$fx}/DejaVuSans-Bold.ttf");
        $flow = $doc->textflow((string) file_get_contents("{$fx}/cjk.txt"), new TextStyle(font: $cjk, size: 10.0, leading: Leading::pt(15.0)), TextFormat::Plain);
        for ($n = 1; $n <= 10; ++$n) {
            if ($flow->isDone()) {
                $flow->reset();
            }
            $page = $doc->addPage(PageSize::A4);
            $page->fitTextline("Page {$n}", $bold, 14.0, new Box(...BENCH_HEADLINE), TextlineOptions::at(Position::centerLeft()));
            $page->fitTextflow($flow, new Box(...BENCH_COLUMN_1));
            $page->fitTextflow($flow, new Box(...BENCH_COLUMN_2));
        }
        $doc->close();

        return 10;
    },

    'svg-100' => static function (string $out, string $fx) use ($newDoc): int {
        $doc = $newDoc($out);
        $art = $doc->svg("{$fx}/art.svg", new NativeConverter());
        for ($n = 0; $n < 10; ++$n) {
            $page = $doc->addPage(PageSize::A4);
            foreach (bench_svg_grid() as $cell) {
                $page->place($art, new Box(...$cell), Fit::meet());
            }
        }
        $doc->close();

        return 10;
    },
];
