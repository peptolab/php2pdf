<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Architecture;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function file_get_contents;
use function preg_match;
use function sort;
use function str_ends_with;
use function strlen;
use function substr;

/**
 * The test suite keeps its own rules: unit tests read no files, and every test class declares
 * the group of the suite it sits in.
 */
#[Group('integration')]
final class TestLayoutTest extends TestCase
{
    /** Calls that read or write files, or load fixtures from disk. */
    private const string FILE_ACCESS =
        '/assetPath|outputDir|file_get_contents|file_put_contents|fopen|tempnam|'
            . 'sys_get_temp_dir|->load\(|::fromFile|readFile|KerningFixtureTrait/';

    /**
     * The test classes of a suite, by path, with their source.
     *
     * @return array<string, string>
     */
    private static function tests(string $suite): array
    {
        $root  = dirname(__DIR__, levels: 2) . "/{$suite}";
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $root,
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));
        $tests = [];
        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || ! str_ends_with($file->getFilename(), 'Test.php')) {
                continue;
            }
            $tests[$suite . substr($file->getPathname(), strlen($root))] = (string) file_get_contents(
                $file->getPathname(),
            );
        }

        return $tests;
    }

    #[Test]
    public function everyTestDeclaresTheGroupOfItsSuite(): void
    {
        $offenders = [];
        foreach (['Unit' => 'unit', 'Integration' => 'integration'] as $suite => $group) {
            foreach (self::tests($suite) as $path => $source) {
                if (1 === preg_match("/#\\[Group\\('{$group}'\\)\\]/", $source)) {
                    continue;
                }
                $offenders[] = $path;
            }
        }
        sort($offenders);

        self::assertSame([], $offenders);
    }

    #[Test]
    public function unitTestsReadNoFiles(): void
    {
        $offenders = [];
        foreach (self::tests('Unit') as $path => $source) {
            if (1 !== preg_match(self::FILE_ACCESS, $source)) {
                continue;
            }
            $offenders[] = $path;
        }

        self::assertSame([], $offenders, 'Move tests that read files to test/Integration.');
    }
}
