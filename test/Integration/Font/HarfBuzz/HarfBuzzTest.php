<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font\HarfBuzz;

use Php2Pdf\Exception\PdfException;
use Php2Pdf\Font\HarfBuzz\HarfBuzz;
use Php2Pdf\Font\HarfBuzz\HarfBuzzCli;
use Php2Pdf\Font\HarfBuzz\HarfBuzzFfi;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function exec;

/** Detecting HarfBuzz: FFI first, then the hb-shape command, else nothing. */
#[Group('integration')]
final class HarfBuzzTest extends TestCase
{
    private const string MISSING_LIBRARY = '/nonexistent/libharfbuzz.0.dylib';

    private static function requireHarfBuzzFfi(): void
    {
        try {
            new HarfBuzzFfi();
        } catch (PdfException $e) {
            self::markTestSkipped($e->getMessage());
        }
    }

    private static function requireHbShape(): void
    {
        $output = [];
        $status = 1;
        exec('command -v hb-shape', $output, $status);
        if (0 !== $status) {
            self::markTestSkipped('hb-shape is not installed.');
        }
    }

    #[Test]
    public function detectsHarfBuzzThroughFfiWhenTheLibraryLoads(): void
    {
        self::requireHarfBuzzFfi();

        self::assertInstanceOf(HarfBuzzFfi::class, HarfBuzz::detect());
    }

    #[Test]
    public function fallsBackToHbShapeWhenTheLibraryDoesNotLoad(): void
    {
        self::requireHbShape();

        self::assertInstanceOf(HarfBuzzCli::class, HarfBuzz::detect(self::MISSING_LIBRARY));
    }

    #[Test]
    public function findsNothingWhenNeitherTheLibraryNorTheCommandIsThere(): void
    {
        self::assertNull(HarfBuzz::detect(self::MISSING_LIBRARY, binary: '/nonexistent/hb-shape'));
    }
}
