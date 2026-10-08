<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Core;

use Closure;
use Override;
use Php2Pdf\Color\IccProfile;
use Php2Pdf\Color\SpotLibrary;
use Php2Pdf\Core\FileReader;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Exception\IoException;
use Php2Pdf\Font\OpenType\OpenTypeFont;
use Php2Pdf\Image\ImageReader;
use Php2Pdf\Import\PdfReader;
use Php2Pdf\Svg\SvgFile;
use Php2Pdf\Text\Hyphenation\Hyphenator;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chmod;
use function file_get_contents;
use function is_file;
use function is_readable;
use function realpath;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

#[Group('integration')]
final class FileReaderTest extends TestCase
{
    private ?string $file = null;

    /** @return array<string, array{0: Closure(string): mixed, 1: string}> */
    public static function loaderProvider(): array
    {
        return [
            'font'                 => [OpenTypeFont::fromFile(...), 'Font file'],
            'image'                => [ImageReader::readFile(...), 'Image file'],
            'image in a document'  => [
                static fn(string $path): mixed => (new Document(new MemoryOutput()))->image($path),
                'Image file',
            ],
            'SVG'                  => [SvgFile::open(...), 'SVG file'],
            'ASE swatches'         => [SpotLibrary::openAse(...), 'Swatch file'],
            'CSV swatches'         => [SpotLibrary::openCsv(...), 'Swatch file'],
            'PDF'                  => [PdfReader::fromFile(...), 'PDF file'],
            'ICC profile'          => [IccProfile::fromFile(...), 'ICC profile'],
            'hyphenation patterns' => [Hyphenator::fromFile(...), 'Hyphenation patterns'],
        ];
    }

    #[Test]
    public function aDirectoryIsNotAFile(): void
    {
        $directory = (string) realpath(sys_get_temp_dir());

        $this->expectException(IoException::class);
        $this->expectExceptionMessage("PDF file \"{$directory}\" not found.");
        FileReader::read($directory, 'PDF file');
    }

    #[Test]
    public function anUnreadableFileIsReportedWithoutAPhpWarning(): void
    {
        $this->file = (string) tempnam((string) realpath(sys_get_temp_dir()), prefix: 'php2pdf-read-');
        chmod($this->file, permissions: 0o000);
        if (is_readable($this->file)) {
            self::markTestSkipped('The file stays readable to this user (root ignores permissions).');
        }

        $this->expectException(IoException::class);
        $this->expectExceptionMessage("Cannot read PDF file \"{$this->file}\".");
        FileReader::read($this->file, 'PDF file');
    }

    /** @param Closure(string): mixed $load */
    #[Test]
    #[DataProvider('loaderProvider')]
    public function eachLoaderReportsAMissingFileByWhatItLoads(Closure $load, string $kind): void
    {
        $this->expectException(IoException::class);
        $this->expectExceptionMessage("{$kind} \"/nonexistent/file\" not found.");
        $load('/nonexistent/file');
    }

    #[Test]
    public function readsTheWholeFile(): void
    {
        $path = PdfTools::assetPath('images/rgb8.png');

        self::assertSame(file_get_contents($path), FileReader::read($path, 'image file'));
    }

    #[Override]
    protected function tearDown(): void
    {
        if (null !== $this->file && is_file($this->file)) {
            chmod($this->file, permissions: 0o600);
            unlink($this->file);
        }
    }
}
