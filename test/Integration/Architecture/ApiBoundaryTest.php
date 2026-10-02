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
use function str_contains;
use function strlen;
use function substr;

/**
 * Every class, interface, trait and enum says whether it is public API (`@api`) or internal
 * (`@internal`), so the boundary is decided for each, not left to chance.
 */
#[Group('integration')]
final class ApiBoundaryTest extends TestCase
{
    #[Test]
    public function everyTypeSaysWhetherItIsPublicApi(): void
    {
        $src   = dirname(__DIR__, levels: 3) . '/src';
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(
            $src,
            RecursiveDirectoryIterator::SKIP_DOTS,
        ));
        $unmarked = [];
        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || 'php' !== $file->getExtension()) {
                continue;
            }
            $path   = substr($file->getPathname(), strlen($src) + 1);
            $source = (string) file_get_contents($file->getPathname());
            $isType = 1 === preg_match('/^(final |abstract |readonly )*(class|interface|trait|enum) /m', $source);
            if (! $isType || str_contains($source, '@api') || str_contains($source, '@internal')) {
                continue;
            }
            $unmarked[] = $path;
        }
        sort($unmarked);

        self::assertSame([], $unmarked);
    }
}
