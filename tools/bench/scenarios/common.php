<?php

/**
 * Geometry and data shared by the php2pdf and PDFlib scenarios, so both lay out the same thing.
 * Boxes are [x, y, width, height] in points on an A4 page with the origin at the bottom left.
 */

declare(strict_types=1);

const BENCH_A4 = [595.2756, 841.8898];

const BENCH_HEADLINE = [42.52, 780.0, 510.24, 20.0];
const BENCH_COLUMN_1 = [42.52, 56.69, 240.94, 700.0];
const BENCH_COLUMN_2 = [311.82, 56.69, 240.94, 700.0];
const BENCH_FOOTER   = [42.52, 20.0, 300.0, 20.0];

const BENCH_TABLE_BOX  = [42.52, 56.69, 510.24, 728.5];
const BENCH_TABLE_COLS = [85.0, 340.24, 85.0];

const BENCH_IMAGE_TOP    = [42.52, 440.0, 510.24, 340.0];
const BENCH_IMAGE_BOTTOM = [42.52, 70.0, 510.24, 340.0];
const BENCH_IMAGE_FULL   = [42.52, 56.69, 510.24, 728.5];

/** @return list<array{string, string, string}> 2,000 rows of reference, wrapping description, amount. */
function bench_table_rows(): array
{
    $words = explode(' ', 'design layout proof colour stock print finish trim bleed fold binding coated uncoated spot process review approval delivery pallet freight');
    $rows  = [];
    for ($i = 1; $i <= 2000; ++$i) {
        $count = 6 + ($i * 7) % 24;
        $desc  = [];
        for ($w = 0; $w < $count; ++$w) {
            $desc[] = $words[($i + $w * 3) % count($words)];
        }
        $rows[] = [sprintf('INV-%05d', $i), ucfirst(implode(' ', $desc)) . '.', sprintf('$%0.2f', ($i * 37) % 10000 / 7)];
    }

    return $rows;
}

/** @return list<string> 20 distinct JPEGs then 20 distinct PNGs. */
function bench_distinct_images(): array
{
    $names = [];
    foreach (['jpg', 'png'] as $ext) {
        for ($i = 1; $i <= 20; ++$i) {
            $names[] = sprintf('img-%02d.%s', $i, $ext);
        }
    }

    return $names;
}

/** @return list<array{float, float, float, float}> Ten boxes, two across and five down. */
function bench_svg_grid(): array
{
    $cells = [];
    for ($row = 0; $row < 5; ++$row) {
        foreach ([42.52, 302.76] as $x) {
            $cells[] = [$x, 56.69 + $row * 148.0, 250.0, 140.0];
        }
    }

    return $cells;
}

/**
 * The body copy in paragraphs of 400 words (about 2,500 characters): long stretches for the
 * shaper and the paragraph composer.
 */
function bench_long_paragraphs(string $copy): string
{
    $words = preg_split('/\s+/', trim($copy)) ?: [];

    return implode("\n\n", array_map(static fn (array $chunk): string => implode(' ', $chunk), array_chunk($words, 400)));
}
