<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font;

use Override;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Font\FontRegistry;
use Php2Pdf\Font\Glyph;
use Php2Pdf\Font\GlyphRun;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function array_fill;
use function file_get_contents;

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
    public function aRunLargerThanTheWholeCacheIsNotKept(): void
    {
        $program = OpenTypeFont::fromData((string) file_get_contents(PdfTools::assetPath('fonts/DejaVuSans.ttf')));
        $small   = new GlyphRun([new Glyph(1, 0x61, 500)]);
        $program->keepShapedRun('small', $small);
        $program->keepShapedRun('huge', new GlyphRun(array_fill(0, count: 100_000, value: new Glyph(1, 0x61, 500))));

        self::assertSame([$small, null], [$program->shapedRun('small'), $program->shapedRun('huge')]);
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

    #[Test]
    public function shapedRunsAreBoundedByTheirSizeNotOnlyTheirCount(): void
    {
        $program = OpenTypeFont::fromData((string) file_get_contents(PdfTools::assetPath('fonts/DejaVuSans.ttf')));
        $run     = new GlyphRun(array_fill(0, count: 1000, value: new Glyph(1, 0x61, 500)));
        for ($n = 0; $n < 100; ++$n) {
            $program->keepShapedRun("run {$n}", $run);
        }

        self::assertSame([null, $run], [$program->shapedRun('run 0'), $program->shapedRun('run 99')]);
    }

    #[Override]
    protected function setUp(): void
    {
        OpenTypeFont::clearCache();
        FontRegistry::clearCache();
    }

    #[Override]
    protected function tearDown(): void
    {
        OpenTypeFont::clearCache();
        FontRegistry::clearCache();
    }
}
