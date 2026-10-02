<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset;

use PHPUnit\Framework\Assert;

use function dirname;
use function file_get_contents;
use function file_put_contents;
use function getenv;
use function is_dir;
use function is_file;
use function mkdir;

/**
 * Byte-level comparison against golden files in test/asset/golden. Set PHP2PDF_UPDATE_BASELINES=1
 * to rewrite them.
 */
final class Golden
{
    public static function assertMatches(string $actual, string $name): void
    {
        $path = PdfTools::assetPath("golden/{$name}");
        if ('1' === getenv('PHP2PDF_UPDATE_BASELINES') || ! is_file($path)) {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), permissions: 0o777, recursive: true);
            }
            file_put_contents($path, $actual);
            Assert::markTestIncomplete("Golden file {$name} was written; re-run to compare.");
        }
        $expected = file_get_contents($path);
        Assert::assertNotFalse($expected);
        Assert::assertSame($expected, $actual, "Output differs from golden file {$name}.");
    }
}
