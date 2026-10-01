<?php

declare(strict_types=1);

namespace PhpPdfTest\Unit\Svg;

use PhpPdf\Core\Output\MemoryOutput;
use PhpPdf\Document\Document;
use PhpPdf\Document\DocumentOptions;
use PhpPdf\Document\PageSize;
use PhpPdf\Geometry\Box;
use PhpPdf\Svg\NativeConverter;
use PhpPdf\Svg\SvgFile;
use PhpPdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function htmlspecialchars;

/** SVG image files are read only from inside the SVG's own directory (#43). */
#[Group('unit')]
final class ImageReferenceTest extends TestCase
{
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
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(compress: false));
        $svg = SvgFile::fromData(
            '<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><image width="10" height="10" href="'
                . htmlspecialchars($href)
                . '"/></svg>',
            null === $directory ? null : PdfTools::assetPath($directory),
        );
        $doc->addPage(PageSize::A4)->place($doc->svg($svg, $converter), new Box(0.0, 0.0, 100.0, 100.0));
        $doc->close();

        return $out->contents();
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
}
