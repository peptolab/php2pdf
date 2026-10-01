<?php

declare(strict_types=1);

namespace PhpPdfTest\Support;

use PHPUnit\Framework\Assert;

/**
 * Byte-level comparison against golden files in test/asset/golden. Set PHPPDF_UPDATE_BASELINES=1
 * to rewrite them.
 */
final class Golden
{
    public static function assertMatches(string $actual, string $name): void
    {
        $path = PdfTools::assetPath('golden/' . $name);
        if ('1' === getenv('PHPPDF_UPDATE_BASELINES') || ! is_file($path)) {
            if (! is_dir(dirname($path))) {
                mkdir(dirname($path), 0o777, true);
            }
            file_put_contents($path, $actual);
            Assert::markTestIncomplete("Golden file {$name} was written; re-run to compare.");
        }
        $expected = file_get_contents($path);
        Assert::assertNotFalse($expected);
        Assert::assertSame($expected, $actual, "Output differs from golden file {$name}.");
    }
}
