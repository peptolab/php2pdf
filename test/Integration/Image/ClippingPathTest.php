<?php

declare(strict_types=1);

namespace Php2PdfTest\Integration\Image;

use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Image\ImageOptions;
use Php2PdfTest\TestAsset\PdfTools;
use Php2PdfTest\TestAsset\PhotoshopPaths;
use Php2PdfTest\TestAsset\TiffFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function str_repeat;

/** Photoshop clipping paths in JPEG and TIFF files clip the image when asked (#26). */
#[Group('integration')]
final class ClippingPathTest extends TestCase
{
    /** The diamond in the image's unit square, starting at the top middle, then the clip and the image. */
    private const string DIAMOND_CLIP = "0.5 1 m\n0.5 1 1 0.5 1 0.5 c\n1 0.5 0.5 0 0.5 0 c\n0.5 0 0 0.5 0 0.5 c\n0 0.5 0.5 1 0.5 1 c\nh\nW* n\nq 1 0 0 1 0 0 cm /";

    /** @return array<string, array{0: bool|string, 1: string}> */
    public static function missingProvider(): array
    {
        return [
            'no marked clipping path' => [true, 'The image has no clipping path.'],
            'no path of that name'    => ['Missing', 'The image has no path named "Missing".'],
        ];
    }

    /** @return array<string, array{0: bool|string}> */
    public static function requestProvider(): array
    {
        return [
            'the marked clipping path' => [true],
            'a path by name'           => ['Outline'],
        ];
    }

    private static function jpeg(): string
    {
        return (string) file_get_contents(PdfTools::assetPath('images/rgb.jpg'));
    }

    #[Test]
    #[DataProvider('missingProvider')]
    public function aMissingPathIsAnError(bool|string $request, string $message): void
    {
        $jpeg = PhotoshopPaths::jpeg(self::jpeg(), PhotoshopPaths::blocks(['Outline' => PhotoshopPaths::DIAMOND]));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage($message);
        $this->draw($jpeg, new ImageOptions(clippingPath: $request));
    }

    #[Test]
    #[DataProvider('requestProvider')]
    public function clipsAJpegToItsPath(bool|string $request): void
    {
        $blocks = PhotoshopPaths::blocks(['Outline' => PhotoshopPaths::DIAMOND, 'Other' => [[0.0, 0.0]]], 'Outline');

        self::assertStringContainsString(self::DIAMOND_CLIP, $this->draw(
            PhotoshopPaths::jpeg(self::jpeg(), $blocks),
            new ImageOptions(clippingPath: $request),
        ));
    }

    #[Test]
    public function clipsATiffToItsPath(): void
    {
        $tiff = (new TiffFixture(2, 2, 2, 8, 3, str_repeat("\x80", times: 12)))->withTags([34_377 => [
            7,
            PhotoshopPaths::blocks(['Outline' => PhotoshopPaths::DIAMOND], 'Outline'),
        ]])
            ->bytes();

        self::assertStringContainsString(self::DIAMOND_CLIP, $this->draw($tiff, new ImageOptions(clippingPath: true)));
    }

    #[Test]
    public function pathsAreIgnoredUnlessAskedFor(): void
    {
        $jpeg = PhotoshopPaths::jpeg(self::jpeg(), PhotoshopPaths::blocks([
            'Outline' => PhotoshopPaths::DIAMOND,
        ], 'Outline'));

        self::assertStringNotContainsString('W* n', $this->draw($jpeg, new ImageOptions()));
    }

    private function draw(string $data, ImageOptions $options): string
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(compress: false));
        $doc->addPage(PageSize::A4)->place($doc->imageFromData($data, $options), new Box(0.0, 0.0, 64.0, 48.0));
        $doc->close();

        return $out->contents();
    }
}
