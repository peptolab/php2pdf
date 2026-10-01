<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Font;

use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Document;
use PhpPdf\Font\FontRegistry;
use PhpPdf\Font\OpenType\OpenTypeFont;
use PhpPdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Parsed fonts and search-path indexes are shared between documents, within bounds (#27). */
#[Group('unit')]
final class FontCacheTest extends TestCase
{
    #[Test]
    public function aFontFileIsParsedOnceForEveryDocument(): void
    {
        $path = PdfTools::assetPath('fonts/DejaVuSans.ttf');

        self::assertSame(OpenTypeFont::fromFile($path), OpenTypeFont::fromFile($path));
    }

    #[Test]
    public function clearingTheCacheParsesTheFileAgain(): void
    {
        $path  = PdfTools::assetPath('fonts/DejaVuSans.ttf');
        $first = OpenTypeFont::fromFile($path);
        OpenTypeFont::clearCache();

        self::assertNotSame($first, OpenTypeFont::fromFile($path));
    }

    #[Test]
    public function documentsShareTheSearchPathIndexUntilItIsCleared(): void
    {
        $faces = static function (): array {
            $doc = new Document(new MemoryOutput());

            return $doc->fonts()->addSearchPath(PdfTools::assetPath('fonts'))->faces();
        };
        $first = $faces();
        FontRegistry::clearCache();

        self::assertEquals($first, $faces());
    }

    protected function setUp(): void
    {
        OpenTypeFont::clearCache();
        FontRegistry::clearCache();
    }

    protected function tearDown(): void
    {
        OpenTypeFont::clearCache();
        FontRegistry::clearCache();
    }
}
