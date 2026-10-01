<?php

/**
 * Generates src/Font/Standard/data/*.php from Adobe's Core 14 AFM files and the Adobe Glyph List.
 *
 * Usage: php tools/generate-standard-fonts.php <dir> && composer cs-fix
 *
 * <dir> holds the 14 .afm files (as shipped with Apache PDFBox), glyphlist.txt and
 * zapfdingbats.txt (from github.com/adobe-type-tools/agl-aglfn).
 *
 * The Latin fonts are re-encoded to WinAnsiEncoding; Symbol and ZapfDingbats keep their built-in
 * encodings, with code points taken from the glyph lists. Kerning pairs are kept between glyphs
 * that have a code. Symbol and ZapfDingbats have no ascender, cap height or x-height in their
 * AFM files, so the font bounding box stands in for them.
 */

declare(strict_types=1);

use PhpPdf\Font\Standard\WinAnsi;

require __DIR__ . '/../vendor/autoload.php';

$source = $argv[1] ?? null;
if (null === $source || ! is_dir($source)) {
    fwrite(STDERR, "Usage: php tools/generate-standard-fonts.php <dir with .afm files and glyph lists>\n");
    exit(1);
}
$target = __DIR__ . '/../src/Font/Standard/data';

/** @return array<string, list<int>> glyph name => code points */
function glyphList(string $file): array
{
    $names = [];
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        if ('' === $line || '#' === $line[0]) {
            continue;
        }
        [$name, $codes] = explode(';', $line);
        $codes = explode(' ', $codes);
        if (1 !== count($codes)) {
            continue;
        }
        $names[$name][] = (int) hexdec($codes[0]);
    }

    return $names;
}

/**
 * @return array{header: array<string, string>, glyphs: array<string, array{code: int, width: int}>, kerning: list<array{0: string, 1: string, 2: int}>}
 */
function parseAfm(string $file): array
{
    $header  = [];
    $glyphs  = [];
    $kerning = [];
    $section = 'header';
    foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
        $line = trim($line);
        if ('' === $line) {
            continue;
        }
        if (str_starts_with($line, 'StartCharMetrics')) {
            $section = 'chars';
            continue;
        }
        if (str_starts_with($line, 'EndCharMetrics')) {
            $section = 'other';
            continue;
        }
        if ('header' === $section) {
            $parts = explode(' ', $line, 2);
            $header[$parts[0]] ??= $parts[1] ?? '';
            continue;
        }
        if ('chars' === $section) {
            preg_match('/C (-?\d+) ; WX (\d+) ; N (\S+) ;/', $line, $m);
            $glyphs[$m[3]] = ['code' => (int) $m[1], 'width' => (int) $m[2]];
            continue;
        }
        if (preg_match('/^KPX (\S+) (\S+) (-?\d+)/', $line, $m)) {
            $kerning[] = [$m[1], $m[2], (int) $m[3]];
        }
    }

    return ['header' => $header, 'glyphs' => $glyphs, 'kerning' => $kerning];
}

$agl    = glyphList("{$source}/glyphlist.txt");
$dingbats = glyphList("{$source}/zapfdingbats.txt");
$byCodePoint = [];
foreach ($agl as $name => $codePoints) {
    foreach ($codePoints as $cp) {
        $byCodePoint[$cp][] = $name;
    }
}
// WinAnsiEncoding shows the no-break space and soft hyphen with the space and hyphen glyphs.
$fallbacks = [0xA0 => 'space', 0xAD => 'hyphen'];

$fonts = [
    'Courier', 'Courier-Bold', 'Courier-Oblique', 'Courier-BoldOblique',
    'Helvetica', 'Helvetica-Bold', 'Helvetica-Oblique', 'Helvetica-BoldOblique',
    'Times-Roman', 'Times-Bold', 'Times-Italic', 'Times-BoldItalic',
    'Symbol', 'ZapfDingbats',
];

foreach ($fonts as $font) {
    $afm      = parseAfm("{$source}/{$font}.afm");
    $header   = $afm['header'];
    $glyphs   = $afm['glyphs'];
    $symbolic = 'Symbol' === $font || 'ZapfDingbats' === $font;

    /** @var array<string, list<int>> $names glyph name => codes */
    $names = [];
    /** @var array<int, int> $codes code point => code */
    $codes = [];
    if ($symbolic) {
        $list = 'ZapfDingbats' === $font ? $dingbats : $agl;
        foreach ($glyphs as $name => $glyph) {
            if ($glyph['code'] < 0) {
                continue;
            }
            $names[$name][] = $glyph['code'];
            foreach ($list[$name] ?? [] as $cp) {
                $codes[$cp] ??= $glyph['code'];
            }
        }
    } else {
        foreach (WinAnsi::map() as $cp => $byte) {
            $candidates = $byCodePoint[$cp] ?? [];
            if (isset($fallbacks[$cp])) {
                $candidates[] = $fallbacks[$cp];
            }
            foreach ($candidates as $name) {
                if (isset($glyphs[$name])) {
                    $names[$name][] = $byte;
                    $codes[$cp]     = $byte;
                    break;
                }
            }
        }
    }

    $widths = [];
    foreach ($names as $name => $list) {
        foreach ($list as $code) {
            $widths[$code] = $glyphs[$name]['width'];
        }
    }
    ksort($widths);
    ksort($codes);

    $kerning = [];
    foreach ($afm['kerning'] as [$left, $right, $value]) {
        foreach ($names[$left] ?? [] as $l) {
            foreach ($names[$right] ?? [] as $r) {
                $kerning[$l][$r] = $value;
            }
        }
    }
    ksort($kerning);
    foreach ($kerning as &$row) {
        ksort($row);
    }
    unset($row);

    $bbox      = array_map('intval', preg_split('/\s+/', trim($header['FontBBox'])) ?: []);
    $ascender  = (int) ($header['Ascender'] ?? $bbox[3]);
    $capHeight = (int) ($header['CapHeight'] ?? $ascender);
    $data      = [
        'name'               => $header['FontName'],
        'encoding'           => $symbolic ? null : 'WinAnsiEncoding',
        'italicAngle'        => (float) $header['ItalicAngle'],
        'fixedPitch'         => 'true' === $header['IsFixedPitch'],
        'bbox'               => $bbox,
        'ascender'           => $ascender,
        'descender'          => (int) ($header['Descender'] ?? $bbox[1]),
        'capHeight'          => $capHeight,
        'xHeight'            => (int) ($header['XHeight'] ?? $capHeight),
        'underlinePosition'  => (int) $header['UnderlinePosition'],
        'underlineThickness' => (int) $header['UnderlineThickness'],
        'stemV'              => (int) ($header['StdVW'] ?? 0),
        'codes'              => $codes,
        'widths'             => $widths,
        'kerning'            => $kerning,
    ];

    $notice = $header['Notice'] ?? '';
    $php    = "<?php\n\n"
        . "/**\n"
        . " * {$header['FontName']} metrics, generated by tools/generate-standard-fonts.php from Adobe's\n"
        . " * Core 14 AFM file: re-encoded and reduced to widths and kerning. See LICENSE-Adobe-Core14.txt.\n"
        . " *\n"
        . " * " . wordwrap($notice, 95, "\n * ") . "\n"
        . " */\n\n"
        . "declare(strict_types=1);\n\n"
        . 'return ' . export($data) . ";\n";
    file_put_contents("{$target}/{$font}.php", $php);
    fwrite(STDOUT, sprintf("%-22s %3d codes, %4d kerning pairs\n", $font, count($codes), array_sum(array_map('count', $kerning))));
}

/** A compact array literal: one line per key, scalars and small lists inline. */
function export(mixed $value, int $depth = 0): string
{
    if (! is_array($value)) {
        return match (true) {
            null === $value => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_float($value) => str_contains((string) $value, '.') ? (string) $value : "{$value}.0",
            is_int($value) => (string) $value,
            default => var_export($value, true),
        };
    }
    $flat = true;
    foreach ($value as $item) {
        if (is_array($item)) {
            $flat = false;
            break;
        }
    }
    $list = array_is_list($value);
    $pairs = [];
    foreach ($value as $key => $item) {
        $pairs[] = ($list ? '' : export($key) . ' => ') . export($item, $depth + 1);
    }
    if ($flat && ($list || $depth > 0)) {
        return '[' . implode(', ', $pairs) . ']';
    }
    $indent = str_repeat('    ', $depth + 1);

    return "[\n{$indent}" . implode(",\n{$indent}", $pairs) . ",\n" . str_repeat('    ', $depth) . ']';
}
