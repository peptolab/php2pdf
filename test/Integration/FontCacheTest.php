<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Font\FontInfo;
use Php2Pdf\Font\FontRegistry;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_map;
use function clearstatcache;
use function copy;
use function glob;
use function is_dir;
use function mkdir;
use function rmdir;
use function touch;
use function unlink;

/** Font caches shared between documents notice files changing on disk (#27). */
#[Group('integration')]
final class FontCacheTest extends TestCase
{
    private string $directory;

    #[Test]
    public function aChangedFontFileIsParsedAgain(): void
    {
        $path = "{$this->directory}/Sans.ttf";
        copy(PdfTools::assetPath('fonts/DejaVuSans.ttf'), $path);
        touch($path, mtime: 1_000_000_000);
        $first = OpenTypeFont::fromFile($path);
        touch($path, mtime: 1_000_000_100);
        clearstatcache();

        self::assertNotSame($first, OpenTypeFont::fromFile($path));
    }

    #[Test]
    public function fontsAddedToASearchPathAreFound(): void
    {
        $faces = fn(): array => array_map(
            static fn(FontInfo $face): string => $face->postScriptName,
            (new Document(new MemoryOutput()))->fonts()
                ->addSearchPath($this->directory)
                ->faces(),
        );
        copy(PdfTools::assetPath('fonts/DejaVuSans.ttf'), "{$this->directory}/Sans.ttf");
        touch($this->directory, mtime: 1_000_000_000);
        $before = $faces();
        copy(PdfTools::assetPath('fonts/SourceSans3-Regular.otf'), "{$this->directory}/Source.otf");
        touch($this->directory, mtime: 1_000_000_100);
        clearstatcache();

        self::assertSame([['DejaVuSans'], ['DejaVuSans', 'SourceSans3-Regular']], [$before, $faces()]);
    }

    protected function setUp(): void
    {
        $this->directory = PdfTools::outputDir() . '/font-cache';
        $this->tearDown();
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        OpenTypeFont::clearCache();
        FontRegistry::clearCache();
        if (! is_dir($this->directory)) {
            return;
        }
        $files = glob("{$this->directory}/*");
        foreach (false === $files ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }
}
