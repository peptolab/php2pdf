<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Image;

use Php2Pdf\Core\Limits;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Document\PageSize;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Image\ImageReader;
use Php2PdfTest\Support\PdfTools;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function file_get_contents;
use function str_contains;
use function substr;
use function substr_replace;

/** JPEG 2000 images are read from their headers and passed through (#26). */
#[Group('unit')]
final class JpxDecoderTest extends TestCase
{
    /** @return array<string, array{0: string, 1: array{0: int, 1: int, 2: string, 3: bool, 4: bool}}> */
    public static function imageProvider(): array
    {
        return [
            'RGB with an ICC profile' => ['rgb.jp2', [64, 48, 'DeviceRGB', true, false]],
            'grey'                    => ['gray.jp2', [32, 24, 'DeviceGray', false, false]],
            'bare codestream'         => ['gray.j2k', [32, 24, 'DeviceGray', false, false]],
            'RGB with alpha'          => ['rgba.jp2', [32, 24, 'DeviceRGB', false, true]],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function malformedProvider(): array
    {
        $jp2 = self::asset('gray.jp2');

        return [
            'truncated file'       => [substr($jp2, offset: 0, length: 20)],
            'no header box'        => [substr_replace($jp2, replace: 'xxxx', offset: 40, length: 4)],
            'truncated codestream' => [substr(self::asset('gray.j2k'), offset: 0, length: 30)],
        ];
    }

    private static function asset(string $file): string
    {
        return (string) file_get_contents(PdfTools::assetPath("images/{$file}"));
    }

    #[Test]
    public function honoursThePixelLimit(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('The image is 32 by 24 pixels, more than the 100 allowed.');
        ImageReader::read(self::asset('gray.jp2'), new Limits(maxImagePixels: 100));
    }

    /** @param array{0: int, 1: int, 2: string, 3: bool, 4: bool} $expected width, height, space, profile, alpha */
    #[Test]
    #[DataProvider('imageProvider')]
    public function readsTheHeader(string $file, array $expected): void
    {
        $data = ImageReader::read(self::asset($file));

        self::assertSame(
            $expected,
            [$data->width, $data->height, $data->colorSpace->device, null !== $data->iccProfile, $data->smaskInData],
        );
    }

    #[Test]
    #[DataProvider('malformedProvider')]
    public function rejectsMalformedData(string $data): void
    {
        $this->expectException(ParseException::class);
        ImageReader::read($data);
    }

    #[Test]
    public function writesTheDataUntouchedWithJpxDecode(): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(compress: false));
        $doc->addPage(PageSize::A4)->place(
            $doc->image(PdfTools::assetPath('images/rgba.jp2')),
            new Box(0.0, 0.0, 32.0, 24.0),
        );
        $doc->close();
        $pdf = $out->contents();

        self::assertSame(
            [true, true, true],
            [
                str_contains($pdf, '/Filter/JPXDecode'),
                str_contains($pdf, '/SMaskInData 1'),
                str_contains($pdf, self::asset('rgba.jp2')),
            ],
        );
    }
}
