<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset;

use PHPUnit\Framework\Assert;

use function bin2hex;
use function dirname;
use function explode;
use function fclose;
use function function_exists;
use function getenv;
use function hex2bin;
use function imagecolorat;
use function imagecreatefrompng;
use function imagesx;
use function imagesy;
use function is_dir;
use function is_executable;
use function mb_convert_encoding;
use function mkdir;
use function pathinfo;
use function preg_match;
use function preg_match_all;
use function proc_close;
use function proc_open;
use function str_contains;
use function stream_get_contents;
use function strpos;
use function strtoupper;
use function substr;
use function trim;

use const PATH_SEPARATOR;
use const PATHINFO_FILENAME;
use const PHP_OS_FAMILY;
use const PREG_SET_ORDER;

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
    /**
     * The bytes an embedded font writes a character with, from the ToUnicode map of an
     * uncompressed PDF: codes follow the order glyphs are first used, so tests look them up.
     */
    public static function code(string $pdf, string $char): string
    {
        $unicode = strtoupper(bin2hex((string) mb_convert_encoding(
            $char,
            to_encoding: 'UTF-16BE',
            from_encoding: 'UTF-8',
        )));
        $match = [];
        Assert::assertSame(
            1,
            preg_match("/<([0-9A-F]{2,6})> <{$unicode}>/", $pdf, $match),
            "No ToUnicode entry maps to \"{$char}\".",
        );

        return (string) hex2bin($match[1] ?? '');
    }

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
        $pipes   = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        Assert::assertIsResource($process);
        $out = $pipes[1] ?? null;
        $err = $pipes[2] ?? null;
        Assert::assertIsResource($out);
        Assert::assertIsResource($err);
        $stdout = stream_get_contents($out);
        $stderr = stream_get_contents($err);
        fclose($out);
        fclose($err);
        $code = proc_close($process);

        return [$code, (string) $stdout . (string) $stderr];
    }

    /** Extracts text from a page with pdftotext, laid out as on the page. */
    public static function text(string $path, int $page = 1): string
    {
        return self::pdftotext($path, $page, '-layout');
    }

    /** Extracts text from a page with pdftotext, in the order the content stream gives it. */
    public static function textInReadingOrder(string $path, int $page = 1): string
    {
        return self::pdftotext($path, $page, '-raw');
    }

    /**
     * The PDF/A rules veraPDF finds a file breaks, as "clause-test: description"; none for a
     * compliant file. $flavour is the part and level, such as 2b or 3b. Skips when veraPDF is
     * not installed.
     *
     * @return list<string>
     */
    public static function veraPdf(string $path, string $flavour): array
    {
        if (null === self::which('verapdf')) {
            Assert::markTestSkipped('veraPDF is not installed.');
        }
        [, $output] = self::run(['verapdf', '-f', $flavour, '--format', 'mrr', $path]);
        Assert::assertStringContainsString(
            '<validationReport',
            $output,
            "veraPDF did not validate {$path}:\n{$output}",
        );
        $rules = [];
        preg_match_all(
            '#<rule [^>]*clause="([^"]+)" testNumber="(\d+)" status="failed"[^>]*>\s*<description>([^<]*)</description>#',
            $output,
            $rules,
            PREG_SET_ORDER,
        );
        $failed = [];
        foreach ($rules as $rule) {
            $failed[] = ($rule[1] ?? '') . '-' . ($rule[2] ?? '') . ': ' . ($rule[3] ?? '');
        }
        if ([] === $failed && ! str_contains($output, 'isCompliant="true"')) {
            $failed[] = 'not compliant';
        }

        return $failed;
    }

    private static function pdftotext(string $path, int $page, string $mode): string
    {
        if (null === self::which('pdftotext')) {
            Assert::markTestSkipped('pdftotext is not installed.');
        }
        [$code, $output] = self::run(['pdftotext', '-f', (string) $page, '-l', (string) $page, $mode, $path, '-']);
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
