<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Image;

use Php2Pdf\Core\Limits;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Image\TiffFile;
use Php2Pdf\Image\TiffPage;
use Php2PdfTest\TestAsset\TiffFixture;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/** A TIFF page's strips and tags are checked as its image is read. */
#[Group('unit')]
final class TiffPageTest extends TestCase
{
    /** @return array<string, array{0: TiffFixture}> */
    public static function mismatchedStripsProvider(): array
    {
        return [
            'uncompressed' => [new TiffFixture(8, 2, 0, 1, 1, "\xAA\x55")],
            'CCITT fax'    => [new TiffFixture(8, 2, 0, 1, 1, "\xAA\x55", compression: 4)],
        ];
    }

    private static function page(TiffFixture $tiff): TiffPage
    {
        return new TiffPage(new TiffFile($tiff->bytes()), 0, Limits::default());
    }

    #[Test]
    public function aFaxInSeveralStripsIsNotReadNatively(): void
    {
        $tiff = (new TiffFixture(8, 2, 0, 1, 1, "\xAA\x55", compression: 4))->inStrips(1);

        self::assertNull(self::page($tiff)->read());
    }

    #[Test]
    public function aFaxStripOutsideTheFileIsNotReadNatively(): void
    {
        $tiff = (new TiffFixture(8, 2, 0, 1, 1, "\xAA\x55", compression: 4))->withTags([279 => [4, [1000]]]);

        self::assertNull(self::page($tiff)->read());
    }

    #[Test]
    public function aLogLuvImageIsNamedInItsTraits(): void
    {
        self::assertSame('LogLuv', self::page(new TiffFixture(1, 1, 32_844, 8, 1, "\0"))->traits()->colourModel);
    }

    #[Test]
    public function anImageWithoutPixelsIsRefused(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('TIFF image has no pixels.');

        self::page(new TiffFixture(0, 1, 1, 8, 1, ''))->read();
    }

    #[Test]
    public function aPaletteImageWithoutAColourMapIsRefused(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('TIFF palette image has no complete colour map.');

        self::page(new TiffFixture(1, 1, 3, 8, 1, "\0"))->read();
    }

    #[Test]
    public function aStripOutsideTheFileIsRefused(): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('TIFF strips lie outside the file.');

        self::page((new TiffFixture(8, 2, 0, 1, 1, "\xAA\x55"))->withTags([279 => [4, [1000]]]))->read();
    }

    #[Test]
    #[DataProvider('mismatchedStripsProvider')]
    public function offsetsAndCountsForDifferentStripsAreRefused(TiffFixture $tiff): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage('TIFF image has no strips.');

        self::page($tiff->withTags([279 => [4, [1, 1]]]))->read();
    }

    #[Test]
    public function packBitsRepeatRunsAreExpandedAndNoOpsSkipped(): void
    {
        $tiff = (new TiffFixture(4, 1, 1, 8, 1, "\x80\xFD\x7F"))->withTags([259 => [3, [32_773]]]);

        self::assertSame("\x7F\x7F\x7F\x7F", self::page($tiff)->read()?->raster->data);
    }
}
