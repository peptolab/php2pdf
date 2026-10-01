<?php

declare(strict_types=1);

namespace Php2PdfTest\Support;

use PHPUnit\Framework\Assert;

use function dirname;
use function explode;
use function fclose;
use function function_exists;
use function getenv;
use function imagecolorat;
use function imagecreatefrompng;
use function imagesx;
use function imagesy;
use function is_dir;
use function is_executable;
use function mkdir;
use function pathinfo;
use function proc_close;
use function proc_open;
use function stream_get_contents;
use function strpos;
use function substr;
use function trim;

use const PATH_SEPARATOR;
use const PATHINFO_FILENAME;
use const PHP_OS_FAMILY;

/**
 * Wraps the external validators (qpdf, poppler, and macOS's own PDF renderer through sips) used
 * by integration tests.
 */
final class PdfTools
{
    /** Fails the test if qpdf reports structural problems. Skips when qpdf is absent. */
    public static function assertValid(string $path): void
    {
        if (! self::hasQpdf()) {
            Assert::markTestSkipped('qpdf is not installed.');
        }
        [$code, $output] = self::run(['qpdf', '--check', $path]);
        Assert::assertSame(0, $code, "qpdf --check failed for {$path}:\n{$output}");
    }

    public static function assetPath(string $relative): string
    {
        return dirname(__DIR__) . '/asset/' . $relative;
    }

    /**
     * Renders page 1 with macOS's PDF renderer (CoreGraphics, as Preview uses) and counts the
     * dark, opaque pixels, sampling every other pixel. Skips off macOS or without ext-gd.
     */
    public static function coreGraphicsInk(string $path): int
    {
        return self::ink(self::coreGraphicsRender($path));
    }

    /**
     * Renders page 1 with macOS's PDF renderer (CoreGraphics, as Preview uses) and returns the
     * PNG path. Skips off macOS or without ext-gd.
     */
    public static function coreGraphicsRender(string $path): string
    {
        if (PHP_OS_FAMILY !== 'Darwin' || null === self::which('sips') || ! function_exists('imagecreatefrompng')) {
            Assert::markTestSkipped('Rendering with CoreGraphics needs macOS (sips) and ext-gd.');
        }
        $png = self::outputDir() . '/' . pathinfo($path, PATHINFO_FILENAME) . '-coregraphics.png';
        [$code, $output] = self::run(['sips', '-s', 'format', 'png', $path, '--out', $png]);
        Assert::assertSame(0, $code, "sips failed for {$path}:\n{$output}");

        return $png;
    }

    /**
     * Renders page 1 with Ghostscript and returns the PNG path. Skips without Ghostscript.
     */
    public static function ghostscriptRender(string $path, int $dpi = 72): string
    {
        if (null === self::which('gs')) {
            Assert::markTestSkipped('Ghostscript is not installed.');
        }
        $png = self::outputDir() . '/' . pathinfo($path, PATHINFO_FILENAME) . '-gs.png';
        [$code, $output] = self::run([
            'gs',
            '-q',
            '-dBATCH',
            '-dNOPAUSE',
            '-dSAFER',
            '-sDEVICE=png16m',
            "-r{$dpi}",
            '-dFirstPage=1',
            '-dLastPage=1',
            "-sOutputFile={$png}",
            $path,
        ]);
        Assert::assertSame(0, $code, "Ghostscript failed for {$path}:\n{$output}");

        return $png;
    }

    public static function hasPoppler(): bool
    {
        return null !== self::which('pdftoppm') && null !== self::which('pdfinfo');
    }

    public static function hasQpdf(): bool
    {
        return null !== self::which('qpdf');
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
            $pos = strpos($line, needle: ':');
            if (false !== $pos) {
                $info[substr($line, offset: 0, length: $pos)] = trim(substr($line, $pos + 1));
            }
        }

        return $info;
    }

    /**
     * Counts the dark, opaque pixels of a PNG, sampling every other pixel. Skips without ext-gd.
     */
    public static function ink(string $png): int
    {
        if (! function_exists('imagecreatefrompng')) {
            Assert::markTestSkipped('Counting pixels needs ext-gd.');
        }
        $image = imagecreatefrompng($png);
        Assert::assertNotFalse($image);
        $ink    = 0;
        $width  = imagesx($image);
        $height = imagesy($image);
        for ($y = 0; $y < $height; $y += 2) {
            for ($x = 0; $x < $width; $x += 2) {
                $pixel = imagecolorat($image, $x, $y);
                if (false === $pixel || (($pixel >> 24) & 0x7F) >= 64 || (($pixel >> 16) & 0xFF) >= 128) {
                    continue;
                }
                ++$ink;
            }
        }

        return $ink;
    }

    public static function outputDir(): string
    {
        $dir = dirname(__DIR__) . '/output';
        if (! is_dir($dir)) {
            mkdir($dir, permissions: 0o777, recursive: true);
        }

        return $dir;
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
            'pdftoppm',
            '-png',
            '-r',
            (string) $dpi,
            '-f',
            (string) $page,
            '-l',
            (string) $page,
            '-singlefile',
            $path,
            $prefix,
        ]);
        Assert::assertSame(0, $code, "pdftoppm failed for {$path}:\n{$output}");

        return "{$prefix}.png";
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

    private static function which(string $binary): ?string
    {
        foreach (explode(PATH_SEPARATOR, (string) getenv('PATH')) as $dir) {
            $candidate = "{$dir}/{$binary}";
            if (is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
