<?php

declare(strict_types=1);

namespace PhpPdfTest\Support;

use PHPUnit\Framework\Assert;

/**
 * Wraps the external validators (qpdf, poppler) used by integration tests.
 */
final class PdfTools
{
    public static function outputDir(): string
    {
        $dir = dirname(__DIR__) . '/output';
        if (! is_dir($dir)) {
            mkdir($dir, 0o777, true);
        }

        return $dir;
    }

    public static function assetPath(string $relative): string
    {
        return dirname(__DIR__) . '/asset/' . $relative;
    }

    public static function hasQpdf(): bool
    {
        return null !== self::which('qpdf');
    }

    public static function hasPoppler(): bool
    {
        return null !== self::which('pdftoppm') && null !== self::which('pdfinfo');
    }

    /** Fails the test if qpdf reports structural problems. Skips when qpdf is absent. */
    public static function assertValid(string $path): void
    {
        if (! self::hasQpdf()) {
            Assert::markTestSkipped('qpdf is not installed.');
        }
        [$code, $output] = self::run(['qpdf', '--check', $path]);
        Assert::assertSame(0, $code, "qpdf --check failed for {$path}:\n{$output}");
    }

    /** @return array<string, string> key => value pairs from pdfinfo */
    public static function info(string $path): array
    {
        if (! self::hasPoppler()) {
            Assert::markTestSkipped('poppler-utils is not installed.');
        }
        [$code, $output] = self::run(['pdfinfo', '-box', $path]);
        Assert::assertSame(0, $code, "pdfinfo failed for {$path}:\n{$output}");
        $info = [];
        foreach (explode("\n", trim($output)) as $line) {
            $pos = strpos($line, ':');
            if (false !== $pos) {
                $info[substr($line, 0, $pos)] = trim(substr($line, $pos + 1));
            }
        }

        return $info;
    }

    /** Extracts text from a page with pdftotext. */
    public static function text(string $path, int $page = 1): string
    {
        if (null === self::which('pdftotext')) {
            Assert::markTestSkipped('pdftotext is not installed.');
        }
        [$code, $output] = self::run(['pdftotext', '-f', (string) $page, '-l', (string) $page, '-layout', $path, '-']);
        Assert::assertSame(0, $code, "pdftotext failed for {$path}:\n{$output}");

        return $output;
    }

    /**
     * Renders a page to PNG with pdftoppm and returns the PNG path.
     */
    public static function render(string $path, int $page = 1, int $dpi = 72): string
    {
        if (! self::hasPoppler()) {
            Assert::markTestSkipped('poppler-utils is not installed.');
        }
        $prefix = self::outputDir() . '/' . pathinfo($path, PATHINFO_FILENAME) . '-p' . $page;
        [$code, $output] = self::run([
            'pdftoppm', '-png', '-r', (string) $dpi, '-f', (string) $page, '-l', (string) $page, '-singlefile', $path, $prefix,
        ]);
        Assert::assertSame(0, $code, "pdftoppm failed for {$path}:\n{$output}");

        return $prefix . '.png';
    }

    /**
     * @param list<string> $command
     *
     * @return array{0: int, 1: string}
     */
    public static function run(array $command): array
    {
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        Assert::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        return [$code, (string) $stdout . (string) $stderr];
    }

    private static function which(string $binary): string|null
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $candidate = $dir . '/' . $binary;
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
