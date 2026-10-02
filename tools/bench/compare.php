<?php

/**
 * Prints two benchmark result files side by side.
 *
 *     php tools/bench/compare.php php2pdf.json pdflib.json
 *
 * Ratios are first ÷ second: 3.0× in the time column means the first library took three times
 * as long. Memory compares the RSS growth during the scenario (RSS Δ), which counts memory
 * allocated by C extensions as well as by PHP.
 */

declare(strict_types=1);

if ($argc !== 3) {
    fwrite(STDERR, "Usage: php tools/bench/compare.php first.json second.json\n");
    exit(2);
}

[$a, $b] = array_map(static function (string $path): array {
    $data = json_decode((string) @file_get_contents($path), true);
    if (! is_array($data)) {
        fwrite(STDERR, "Cannot read {$path}.\n");
        exit(1);
    }

    return $data;
}, [$argv[1], $argv[2]]);

printf("A  %s | PHP %s\nB  %s | PHP %s\n\n", $a['meta']['library'], $a['meta']['php'], $b['meta']['library'], $b['meta']['php']);
printf("%-20s %6s | %9s %9s %7s | %9s %9s %7s | %9s %9s\n", 'scenario', 'pages', 'A s', 'B s', 'A÷B', 'A RSSΔ', 'B RSSΔ', 'A÷B', 'A KiB', 'B KiB');
$ratio = static fn (float $x, float $y): string => $y > 0 ? sprintf('%.2f×', $x / $y) : 'n/a';
foreach ($a['results'] as $name => $ra) {
    if (! isset($b['results'][$name])) {
        continue;
    }
    $x = $ra['median'];
    $y = $b['results'][$name]['median'];
    printf(
        "%-20s %6d | %9.3f %9.3f %7s | %8.1fM %8.1fM %7s | %9.0f %9.0f\n",
        $name,
        $x['pages'],
        $x['seconds'],
        $y['seconds'],
        $ratio($x['seconds'], $y['seconds']),
        $x['rss_delta'] / 1_048_576,
        $y['rss_delta'] / 1_048_576,
        $ratio($x['rss_delta'], $y['rss_delta']),
        $x['bytes'] / 1024,
        $y['bytes'] / 1024,
    );
}
if (str_contains($a['meta']['library'] . $b['meta']['library'], 'evaluation')) {
    echo "\nPDFlib ran in evaluation mode: its pages carry a stamp, so its sizes and blank-500 times include it.\n";
}
