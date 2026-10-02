<?php

/**
 * Runs the speed and memory benchmarks for one library.
 *
 *     php tools/bench/run.php --lib=php2pdf|pdflib [--only=text-500,table-2000] [--runs=3]
 *                             [--warmup=1] [--json=results.json] [--keep]
 *
 * Every run is a fresh child process, so peak memory belongs to that scenario alone. Times are
 * wall-clock from the first library call to the file being closed. Two memory figures are kept:
 * the Zend heap peak (memory_get_peak_usage), which only sees PHP allocations, and the process
 * RSS high-water mark (getrusage), which also sees memory a C extension such as PDFlib allocates.
 * Compare libraries on RSS.
 *
 * Children run with XDEBUG_MODE=off: Xdebug in coverage mode makes php2pdf about four times slower.
 * A scenario returning 0 pages was skipped (a fixture it needs is missing).
 */

declare(strict_types=1);

const BENCH_DIR = __DIR__;

$opts = getopt('', ['lib:', 'only:', 'runs:', 'warmup:', 'json:', 'keep', 'child', 'scenario:', 'out:']);
$lib  = (string) ($opts['lib'] ?? '');
if (! in_array($lib, ['php2pdf', 'pdflib'], true)) {
    fwrite(STDERR, "Usage: php tools/bench/run.php --lib=php2pdf|pdflib [--only=a,b] [--runs=3] [--warmup=1] [--json=file] [--keep]\n");
    exit(2);
}

$fixtures = BENCH_DIR . '/fixtures';
if (! is_file("{$fixtures}/manifest.json")) {
    fwrite(STDERR, "No fixtures found. Run: php tools/bench/fixtures.php\n");
    exit(1);
}

function bench_rss_bytes(): int
{
    $max = getrusage()['ru_maxrss'];

    return PHP_OS_FAMILY === 'Darwin' ? (int) $max : (int) $max * 1024;
}

// Child: run one scenario once and print one line of JSON.
if (isset($opts['child'])) {
    $scenarios = require BENCH_DIR . "/scenarios/{$lib}.php";
    $name      = (string) $opts['scenario'];
    $out       = (string) $opts['out'];
    gc_collect_cycles();
    $rssBase  = bench_rss_bytes();
    $zendBase = memory_get_usage(true);
    $start    = hrtime(true);
    $pages    = $scenarios[$name]($out, $fixtures);
    $seconds  = (hrtime(true) - $start) / 1e9;
    clearstatcache();
    echo json_encode([
        'seconds'   => $seconds,
        'pages'     => $pages,
        'bytes'     => (int) filesize($out),
        'zend_base' => $zendBase,
        'zend_peak' => memory_get_peak_usage(true),
        'rss_base'  => $rssBase,
        'rss_peak'  => bench_rss_bytes(),
    ]), "\n";
    exit(0);
}

$scenarios = require BENCH_DIR . "/scenarios/{$lib}.php";
$names     = array_keys($scenarios);
if (isset($opts['only'])) {
    $names = array_values(array_intersect($names, explode(',', (string) $opts['only'])));
}
$runs   = max(1, (int) ($opts['runs'] ?? 3));
$warmup = max(0, (int) ($opts['warmup'] ?? 1));
$keep   = isset($opts['keep']);
$outDir = BENCH_DIR . '/out';
if (! is_dir($outDir)) {
    mkdir($outDir, 0o777, true);
}

function bench_cpu(): string
{
    if (is_readable('/proc/cpuinfo') && preg_match('/model name\s*:\s*(.+)/', (string) file_get_contents('/proc/cpuinfo'), $m)) {
        return trim($m[1]);
    }
    $sysctl = @shell_exec('sysctl -n machdep.cpu.brand_string 2>/dev/null');

    return is_string($sysctl) && '' !== trim($sysctl) ? trim($sysctl) : php_uname('m');
}

function bench_lib_version(string $lib): string
{
    if ('pdflib' === $lib) {
        $p = new PDFlib();
        $v = sprintf('PDFlib %d.%d.%d', $p->get_option('major', ''), $p->get_option('minor', ''), $p->get_option('revision', ''));

        return $v . ((getenv('PDFLIB_LICENCE') ?: getenv('PDFLIB_LICENSE')) ? ' (licensed)' : ' (evaluation mode: pages are stamped)');
    }
    $head = trim((string) @shell_exec('git -C ' . escapeshellarg(dirname(BENCH_DIR, 2)) . ' rev-parse --short HEAD 2>/dev/null'));

    return 'php2pdf ' . ('' !== $head ? "@{$head}" : '(working copy)');
}

function bench_median(array $values): float
{
    sort($values);
    $n = count($values);

    return $n % 2 ? (float) $values[intdiv($n, 2)] : ($values[$n / 2 - 1] + $values[$n / 2]) / 2;
}

$meta = [
    'library' => bench_lib_version($lib),
    'php'     => PHP_VERSION . ' ' . PHP_OS_FAMILY . ' ' . php_uname('r') . ' ' . php_uname('m'),
    'cpu'     => bench_cpu(),
    'opcache' => (ini_get('opcache.enable_cli') ? 'on' : 'off') . ', jit ' . (ini_get('opcache.jit') ?: 'off') . ' (' . (ini_get('opcache.jit_buffer_size') ?: '0') . ')',
    'memory_limit' => (string) ini_get('memory_limit'),
    'xdebug'  => extension_loaded('xdebug') ? 'loaded, off in the runs' : 'not loaded',
    'runs'    => $runs,
    'warmup'  => $warmup,
];
foreach ($meta as $k => $v) {
    printf("%-13s %s\n", $k, $v);
}
echo "\n";

// Children inherit settings given to this process with -d (opcache, JIT, memory limit).
$iniArgs = [];
foreach (['opcache.enable_cli', 'opcache.jit', 'opcache.jit_buffer_size', 'memory_limit'] as $key) {
    $value = ini_get($key);
    if (false !== $value && '' !== $value) {
        $iniArgs[] = '-d';
        $iniArgs[] = "{$key}={$value}";
    }
}

$mib     = static fn (float $b): float => $b / 1_048_576;
$results = [];
printf("%-20s %6s %10s %10s %9s %10s %10s %10s %10s\n", 'scenario', 'pages', 'median s', 'min s', 'ms/page', 'RSS MiB', 'RSS Δ MiB', 'Zend MiB', 'size KiB');
foreach ($names as $name) {
    $samples = [];
    for ($i = 0; $i < $warmup + $runs; ++$i) {
        $out = "{$outDir}/{$lib}-{$name}.pdf";
        $cmd = [PHP_BINARY, ...$iniArgs, __FILE__, '--child', "--lib={$lib}", "--scenario={$name}", "--out={$out}"];
        $proc = proc_open($cmd, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, ['XDEBUG_MODE' => 'off'] + getenv());
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);
        $lines = array_filter(explode("\n", trim((string) $stdout)));
        $data  = json_decode((string) end($lines), true);
        if (0 !== $code || ! is_array($data)) {
            fwrite(STDERR, "{$name} failed (exit {$code}):\n{$stdout}{$stderr}\n");
            continue 2;
        }
        if (0 === $data['pages']) {
            printf("%-20s skipped: a fixture it needs is missing\n", $name);
            continue 2;
        }
        if ($i >= $warmup) {
            $samples[] = $data;
        }
        if (! $keep) {
            @unlink($out);
        }
    }
    $col     = static fn (string $k): array => array_column($samples, $k);
    $rssPeak = bench_median($col('rss_peak'));
    $rssBase = bench_median($col('rss_base'));
    $median  = [
        'seconds'   => bench_median($col('seconds')),
        'min'       => min($col('seconds')),
        'pages'     => $samples[0]['pages'],
        'bytes'     => $samples[0]['bytes'],
        'rss_peak'  => $rssPeak,
        'rss_delta' => bench_median(array_map(static fn (array $s): int => $s['rss_peak'] - $s['rss_base'], $samples)),
        'zend_peak' => bench_median($col('zend_peak')),
    ];
    $results[$name] = ['median' => $median, 'runs' => $samples];
    printf(
        "%-20s %6d %10.3f %10.3f %9.2f %10.1f %10.1f %10.1f %10.0f\n",
        $name,
        $median['pages'],
        $median['seconds'],
        $median['min'],
        1000 * $median['seconds'] / max(1, $median['pages']),
        $mib($median['rss_peak']),
        $mib($median['rss_delta']),
        $mib($median['zend_peak']),
        $median['bytes'] / 1024,
    );
}

if (isset($opts['json'])) {
    file_put_contents((string) $opts['json'], json_encode(['meta' => $meta, 'results' => $results], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n");
    echo "\nWrote {$opts['json']}\n";
}
