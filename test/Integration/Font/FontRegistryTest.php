<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Font;

use Override;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Font\FontInfo;
use Php2Pdf\Font\FontRegistry;
use Php2Pdf\Font\FontStyle;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function copy;
use function file_put_contents;
use function glob;
use function is_dir;
use function mkdir;
use function realpath;
use function rmdir;
use function str_repeat;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

#[Group('integration')]
final class FontRegistryTest extends TestCase
{
    private ?string $directory = null;

    #[Test]
    public function aMalformedFontInASearchPathIsSkipped(): void
    {
        $directory = $this->directory();
        file_put_contents("{$directory}/Broken.ttf", "\x00\x01\x00\x00\x00\x05" . str_repeat("\x00", times: 6));
        copy(PdfTools::assetPath('fonts/DejaVuSans.ttf'), "{$directory}/DejaVuSans.ttf");
        $faces = [];
        foreach ((new Document(new MemoryOutput()))->fonts()
            ->addSearchPath($directory)
            ->faces() as $face) {
            $faces[] = $face->postScriptName;
        }

        self::assertSame(['DejaVuSans'], $faces);
    }

    #[Test]
    public function aSearchPathAddedTwiceIsListedOnce(): void
    {
        $registry = (new Document(new MemoryOutput()))->fonts();
        $registry->addSearchPath(PdfTools::assetPath('fonts'));
        $registry->addSearchPath(PdfTools::assetPath('fonts') . '/.');

        self::assertSame([realpath(PdfTools::assetPath('fonts'))], $registry->searchPaths());
    }

    #[Test]
    public function aSearchPathMustBeADirectory(): void
    {
        $path = PdfTools::assetPath('fonts/DejaVuSans.ttf');

        $this->expectException(IoException::class);
        $this->expectExceptionMessage("Font search path \"{$path}\" is not a directory.");

        (new Document(new MemoryOutput()))->fonts()
            ->addSearchPath($path);
    }

    #[Test]
    public function findsFontsByNameAndGroupsFamilies(): void
    {
        $doc      = new Document(new MemoryOutput());
        $registry = $doc->fonts()->addSearchPath(PdfTools::assetPath('fonts'));

        self::assertTrue($registry->has('DejaVuSans-Bold'));
        self::assertTrue($registry->has('dejavu sans bold'));
        self::assertTrue($registry->has('DejaVu Sans'));
        self::assertFalse($registry->has('Futura'));
        self::assertTrue($registry->has('Helvetica'), 'The standard 14 fonts are always available.');

        $bold = $registry->find('DejaVu Sans Bold');
        self::assertSame('DejaVuSans-Bold', $bold->name());
        self::assertSame($bold, $registry->load('DejaVuSans-Bold'), 'The same file embeds once.');

        $family = $registry->family('dejavu sans');
        self::assertSame('DejaVu Sans', $family->name);
        self::assertTrue($family->has(FontStyle::Bold));
        self::assertFalse($family->has(FontStyle::Italic));
        self::assertSame('DejaVuSans', $family->regular()->name());
        self::assertSame($bold, $family->bold());
    }

    #[Test]
    public function peeksNamesAndStyleWithoutFullParse(): void
    {
        $infos = FontInfo::peek(PdfTools::assetPath('fonts/DejaVuSans-Bold.ttf'));

        self::assertCount(1, $infos);
        $info = $infos[0] ?? null;
        self::assertNotNull($info);
        self::assertSame('DejaVu Sans', $info->family);
        self::assertSame('Bold', $info->subfamily);
        self::assertSame('DejaVuSans-Bold', $info->postScriptName);
        self::assertSame(FontStyle::Bold, $info->style);
    }

    #[Test]
    public function reportsMissingFiles(): void
    {
        $doc = new Document(new MemoryOutput());

        $this->expectException(IoException::class);
        $doc->fonts()->load('/nowhere/font.ttf');
    }

    #[Test]
    public function reportsMissingFonts(): void
    {
        $doc = new Document(new MemoryOutput());

        $this->expectException(InvalidArgumentException::class);
        $doc->fonts()->find('Nope');
    }

    #[Override]
    protected function tearDown(): void
    {
        if (null === $this->directory || ! is_dir($this->directory)) {
            return;
        }
        $files = glob("{$this->directory}/*");
        foreach (false === $files ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    private function directory(): string
    {
        FontRegistry::clearCache();
        $this->directory = sys_get_temp_dir() . '/php2pdf-registry-' . uniqid();
        mkdir($this->directory);

        return $this->directory;
    }
}
