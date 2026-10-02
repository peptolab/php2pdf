<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Font\FontRegistry;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** Parsed fonts and search-path indexes are shared between documents, within bounds (#27). */
#[Group('integration')]
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
