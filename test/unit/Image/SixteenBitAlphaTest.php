<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Image;

use Php2Pdf\Core\Filter\Flate;
use Php2Pdf\Core\FixedClock;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Fit\Fit;
use Php2Pdf\Geometry\Box;
use Php2Pdf\Image\ImageData;
use Php2Pdf\Image\ImageReader;
use Php2PdfTest\Support\TiffFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function gzcompress;
use function pack;
use function strlen;
use function substr_count;

/** Images with 16-bit samples and alpha keep every bit of colour and of the soft mask. */
#[Group('unit')]
final class SixteenBitAlphaTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string, 2: string}> */
    public static function imageProvider(): array
    {
        return [
            'PNG RGBA'                        => [
                self::png(2, 6, "\x12\x34\x56\x78\x9A\xBC\xDE\xF0\x01\x02\x03\x04\x05\x06\x07\x08"),
                "\x12\x34\x56\x78\x9A\xBC\x01\x02\x03\x04\x05\x06",
                "\xDE\xF0\x07\x08",
            ],
            'PNG grey and alpha'              => [
                self::png(2, 4, "\x12\x34\x56\x78\x9A\xBC\xDE\xF0"),
                "\x12\x34\x9A\xBC",
                "\x56\x78\xDE\xF0",
            ],
            'TIFF RGBA, little-endian'        => [
                (new TiffFixture(1, 1, 2, 16, 4, "\x34\x12\x78\x56\xBC\x9A\xF0\xDE"))->withTags([338 => [3, [2]]])
                    ->bytes(),
                "\x12\x34\x56\x78\x9A\xBC",
                "\xDE\xF0",
            ],
            'TIFF grey and alpha, big-endian' => [
                (new TiffFixture(1, 1, 1, 16, 2, "\x12\x34\x56\x78"))->withTags([338 => [3, [2]]])
                    ->bigEndian()
                    ->bytes(),
                "\x12\x34",
                "\x56\x78",
            ],
        ];
    }

    private static function colour(ImageData $image): string
    {
        return null === $image->filter ? $image->data : Flate::decode($image->data);
    }

    /** A 16-bit PNG of one row, unfiltered. */
    private static function png(int $width, int $type, string $row): string
    {
        $chunk = static fn(string $name, string $data): string => (
            pack('N', strlen($data)) . $name . $data . pack('N', 0)
        );

        return (
            "\x89PNG\r\n\x1A\n"
                . $chunk('IHDR', pack('NNCCCCC', $width, 1, 16, $type, 0, 0, 0))
                . $chunk('IDAT', (string) gzcompress("\0{$row}"))
                . $chunk('IEND', data: '')
        );
    }

    #[Test]
    #[DataProvider('imageProvider')]
    public function keepsBothBytesOfEveryColourAndAlphaSample(string $file, string $colour, string $alpha): void
    {
        $image = ImageReader::read($file);

        self::assertSame(
            [16, $colour, 16, $alpha],
            [$image->bitsPerComponent, self::colour($image), $image->softMaskBits, $image->softMask],
        );
    }

    #[Test]
    public function writesASixteenBitSoftMask(): void
    {
        $out = new MemoryOutput();
        $doc = new Document($out, new DocumentOptions(
            clock: new FixedClock('2026-01-01'),
            compress: false,
            xmp: false,
        ));
        $png = $doc->imageFromData(self::png(2, 6, "\x12\x34\x56\x78\x9A\xBC\xDE\xF0\x01\x02\x03\x04\x05\x06\x07\x08"));
        $doc->addPage()->place($png, new Box(0.0, 0.0, 20.0, 10.0), Fit::entire());
        $doc->close();

        self::assertSame(
            [1, 1],
            [
                substr_count(
                    $out->contents(),
                    needle: '/Width 2/Height 1/ColorSpace/DeviceRGB/BitsPerComponent 16/Filter/FlateDecode',
                ),
                substr_count(
                    $out->contents(),
                    needle: "/Width 2/Height 1/ColorSpace/DeviceGray/BitsPerComponent 16/Length 4>>\nstream\n\xDE\xF0\x07\x08",
                ),
            ],
        );
    }
}
