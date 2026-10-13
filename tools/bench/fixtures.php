<?php

/**
 * Builds the shared benchmark fixtures in tools/bench/fixtures/.
 *
 * Run once, with any PHP that has ext-gd, before running the benchmarks. Both the php2pdf and the
 * PDFlib scenarios read the same files, so run it once and use the same fixtures for both.
 *
 *     php tools/bench/fixtures.php [--force]
 */

declare(strict_types=1);

$root = dirname(__DIR__, 2);
$dir  = __DIR__ . '/fixtures';
$force = in_array('--force', $argv, true);

if (! extension_loaded('gd')) {
    fwrite(STDERR, "fixtures.php needs ext-gd to generate the test images.\n");
    exit(1);
}
if (is_file("{$dir}/manifest.json") && ! $force) {
    echo "Fixtures already exist in {$dir} (pass --force to rebuild).\n";
    exit(0);
}
if (! is_dir($dir) && ! mkdir($dir, 0o777, true)) {
    fwrite(STDERR, "Cannot create {$dir}.\n");
    exit(1);
}

// Copied assets: fonts, a third-party PDF (cairo) as the import template, a text-free SVG.
$copies = [
    'DejaVuSans.ttf'      => 'test/asset/fonts/DejaVuSans.ttf',
    'DejaVuSans-Bold.ttf' => 'test/asset/fonts/DejaVuSans-Bold.ttf',
    'SourceSans3-Regular.otf'  => 'test/asset/fonts/SourceSans3-Regular.otf',
    'SourceSans3-Bold.otf'     => 'test/asset/fonts/SourceSans3-Bold.otf',
    'SourceSerif4-Regular.ttf' => 'test/asset/fonts/SourceSerif4-Regular.ttf',
    'SourceSerif4-Bold.ttf'    => 'test/asset/fonts/SourceSerif4-Bold.ttf',
    'template.pdf'        => 'test/asset/pdf/cairo.pdf',
    'art.svg'             => 'test/asset/svg/shapes.svg',
];
foreach ($copies as $name => $source) {
    if (! copy("{$root}/{$source}", "{$dir}/{$name}")) {
        fwrite(STDERR, "Cannot copy {$source}.\n");
        exit(1);
    }
}

// English hyphenation patterns (hunspell format) for hyphenate-100. They are not bundled with
// php2pdf; set BENCH_HYPHEN to a hyph_en_*.dic file, or one is looked for in common places.
$hyphen = array_values(array_filter([
    getenv('BENCH_HYPHEN') ?: null,
    '/usr/share/hyphen/hyph_en_US.dic',
    '/usr/share/hunspell/hyph_en_US.dic',
    '/opt/homebrew/share/hyphen/hyph_en_US.dic',
    '/System/Library/ScreenReader/BrailleTables/LiblouisBrailleTranslator.brailletable/Contents/Resources/liblouis/tables/hyph_en_US.dic',
], static fn (?string $path): bool => null !== $path && is_file($path)));
if ([] === $hyphen) {
    echo "  No English hyphenation patterns found: hyphenate-100 will be skipped (set BENCH_HYPHEN).\n";
} elseif (! copy($hyphen[0], "{$dir}/hyph-en.dic")) {
    fwrite(STDERR, "Cannot copy {$hyphen[0]}.\n");
    exit(1);
}

// A large CJK font for cjk-10: tens of thousands of glyphs, too big to bundle. Set BENCH_CJK to
// a font file (face 0 of a collection is used), or one is looked for in common places.
$cjk = array_values(array_filter([
    getenv('BENCH_CJK') ?: null,
    '/usr/share/fonts/opentype/noto/NotoSansCJK-Regular.ttc',
    '/usr/share/fonts/noto-cjk/NotoSansCJK-Regular.ttc',
    '/System/Library/Fonts/Supplemental/Arial Unicode.ttf',
    '/System/Library/Fonts/PingFang.ttc',
], static fn (?string $path): bool => null !== $path && is_file($path)));
foreach (glob("{$dir}/cjk.*") ?: [] as $old) {
    unlink($old);
}
if ([] === $cjk) {
    echo "  No CJK font found: cjk-10 will be skipped (set BENCH_CJK).\n";
} elseif (! copy($cjk[0], "{$dir}/cjk." . strtolower(pathinfo($cjk[0], PATHINFO_EXTENSION)))) {
    fwrite(STDERR, "Cannot copy {$cjk[0]}.\n");
    exit(1);
} else {
    echo '  CJK font: ' . basename($cjk[0]) . "\n";
}

// CJK copy for cjk-10: sentences of ideographs drawn from 3,500 of them, about as many different
// characters as a long document uses, so the subset holds thousands of glyphs, not a handful.
mt_srand(49);
$sentences = [];
for ($i = 0; $i < 1200; ++$i) {
    $sentence = '';
    for ($n = mt_rand(8, 24); $n > 0; --$n) {
        $sentence .= mb_chr(0x4E00 + mt_rand(0, 3499), 'UTF-8');
    }
    $sentences[] = $sentence . (0 === $i % 3 ? '。' : '，');
}
file_put_contents("{$dir}/cjk.txt", implode('', array_map(
    static fn (array $paragraph): string => implode('', $paragraph) . "\n",
    array_chunk($sentences, 12),
)));

// Body copy: the brochure text with its markup removed, so both libraries set plain text.
$brochure = (string) file_get_contents("{$root}/test/asset/text/brochure.txt");
$plain    = html_entity_decode(strip_tags(str_replace(['<br>', '<tab>'], ["\n", ' '], $brochure)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
$plain    = trim((string) preg_replace("/\n{3,}/", "\n\n", $plain));
file_put_contents("{$dir}/body.txt", str_repeat($plain . "\n\n", 4));

/** Draws a deterministic, photo-like busy image so codecs have real work to do. */
function paint(int $width, int $height, int $seed): GdImage
{
    mt_srand($seed);
    $im = imagecreatetruecolor($width, $height);
    for ($y = 0; $y < $height; $y += 4) {
        $c = imagecolorallocate($im, (int) (255 * $y / $height), 80 + ($seed * 13) % 120, 255 - (int) (255 * $y / $height));
        imagefilledrectangle($im, 0, $y, $width - 1, $y + 3, $c);
    }
    $shapes = (int) ($width * $height / 6000);
    for ($i = 0; $i < $shapes; ++$i) {
        $c = imagecolorallocatealpha($im, mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), mt_rand(20, 90));
        imagefilledellipse($im, mt_rand(0, $width), mt_rand(0, $height), mt_rand(8, 160), mt_rand(8, 160), $c);
    }
    imagefilter($im, IMG_FILTER_SMOOTH, 6);

    return $im;
}

$images = [
    ['photo.jpg', 3000, 2000, 1],
    ['photo.png', 2000, 1333, 2],
];
for ($i = 1; $i <= 20; ++$i) {
    $images[] = [sprintf('img-%02d.jpg', $i), 1600, 1200, 100 + $i];
    $images[] = [sprintf('img-%02d.png', $i), 1200, 900, 200 + $i];
}
foreach ($images as [$name, $w, $h, $seed]) {
    $im = paint($w, $h, $seed);
    str_ends_with($name, '.jpg') ? imagejpeg($im, "{$dir}/{$name}", 85) : imagepng($im, "{$dir}/{$name}", 6);
    echo "  {$name} {$w}x{$h}\n";
}

$manifest = [];
foreach (glob("{$dir}/*") ?: [] as $file) {
    if (basename($file) !== 'manifest.json') {
        $manifest[basename($file)] = ['bytes' => filesize($file), 'sha1' => sha1_file($file)];
    }
}
file_put_contents("{$dir}/manifest.json", json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo 'Wrote ' . count($manifest) . " fixtures to {$dir}\n";
