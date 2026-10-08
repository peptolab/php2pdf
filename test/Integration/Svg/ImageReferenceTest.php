<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Svg;

use Override;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Svg\NativeConverter;
use Php2Pdf\Svg\SvgFile;
use Php2PdfTest\TestAsset\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function chmod;
use function copy;
use function htmlspecialchars;
use function is_dir;
use function is_file;
use function is_readable;
use function mkdir;
use function realpath;
use function rmdir;
use function sys_get_temp_dir;
use function tempnam;
use function unlink;

/** SVG image files are read only from inside the SVG's own directory (#43). */
#[Group('integration')]
final class ImageReferenceTest extends TestCase
{
    private ?string $directory = null;

    /** @return array<string, array{0: string|null, 1: string, 2: list<string>}> */
    public static function referenceProvider(): array
    {
        $outside = ['image files outside the SVG\'s directory'];

        return [
            'inside the directory'  => ['images', 'rgb8.png', []],
            'percent-encoded name'  => ['images', 'rgb%38.png', []],
            'climbing out with ..'  => ['fonts', '../images/rgb8.png', $outside],
            'absolute path'         => ['images', PdfTools::assetPath('images/rgb8.png'), $outside],
            'SVG with no directory' => [null, 'rgb8.png', $outside],
            'missing file'          => ['images', 'nothing.png', ['missing image files']],
        ];
    }

    private static function draw(?string $directory, string $href, NativeConverter $converter): string
    {
        return self::drawFrom(null === $directory ? null : PdfTools::assetPath($directory), $href, $converter);
    }

    private static function drawFrom(?string $baseDirectory, string $href, NativeConverter $converter): string
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(compress: false));
        $svg = SvgFile::fromData(
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><image width="10" height="10" href="'
                . htmlspecialchars($href)
                . '"/></svg>',
            $baseDirectory,
        );
        $doc->addPage(PageSize::A4)->place($doc->svg($svg, $converter), new Box(0.0, 0.0, 100.0, 100.0));
        $doc->close();

        return $out->contents();
    }

    #[Test]
    public function anUnreadableImageFileIsLeftOutWithAWarning(): void
    {
        $this->directory = (string) tempnam((string) realpath(sys_get_temp_dir()), prefix: 'php2pdf-svg-');
        unlink($this->directory);
        mkdir($this->directory);
        copy(PdfTools::assetPath('images/rgb8.png'), "{$this->directory}/rgb8.png");
        chmod("{$this->directory}/rgb8.png", permissions: 0o000);
        if (is_readable("{$this->directory}/rgb8.png")) {
            self::markTestSkipped('The file stays readable to this user (root ignores permissions).');
        }
        $converter = new NativeConverter();
        self::drawFrom($this->directory, 'rgb8.png', $converter);

        self::assertSame(['unreadable image files'], $converter->warnings());
    }

    #[Test]
    public function embedsAnImageInsideTheDirectory(): void
    {
        self::assertStringContainsString('/Subtype/Image', self::draw('images', 'rgb8.png', new NativeConverter()));
    }

    #[Test]
    public function embedsNothingForARefusedReference(): void
    {
        self::assertStringNotContainsString(
            '/Subtype/Image',
            self::draw('fonts', '../images/rgb8.png', new NativeConverter()),
        );
    }

    /** @param list<string> $warnings */
    #[Test]
    #[DataProvider('referenceProvider')]
    public function readsImageFilesOnlyFromTheSvgsDirectory(?string $directory, string $href, array $warnings): void
    {
        $converter = new NativeConverter();
        self::draw($directory, $href, $converter);

        self::assertSame($warnings, $converter->warnings());
    }

    #[Override]
    protected function tearDown(): void
    {
        if (null === $this->directory || ! is_dir($this->directory)) {
            return;
        }
        if (is_file("{$this->directory}/rgb8.png")) {
            chmod("{$this->directory}/rgb8.png", permissions: 0o600);
            unlink("{$this->directory}/rgb8.png");
        }
        rmdir($this->directory);
    }
}
