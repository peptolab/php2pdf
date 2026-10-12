<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Image;

use Php2Pdf\Core\Limits;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Image\ImageData;
use Php2Pdf\Image\ImageOptions;
use Php2Pdf\Image\Jbig2Decoder;
use Php2PdfTest\TestAsset\Image\Jbig2Segments;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function implode;
use function pack;
use function str_replace;

/**
 * A JBIG2 file's page becomes the image data PDF reads with JBIG2Decode: its segments on page 1,
 * without the file header or the end-of-page and end-of-file segments, and the segments every
 * page shares as its globals.
 */
#[Group('unit')]
final class Jbig2DecoderTest extends TestCase
{
    /** @return array<string, array{0: string, 1: bool}> */
    public static function acceptsProvider(): array
    {
        return [
            'the JBIG2 signature' => [Jbig2Segments::file(Jbig2Segments::barPage()), true],
            'a PNG'               => ["\x89PNG\r\n\x1A\n", false],
            'nothing'             => ['', false],
        ];
    }

    /** @return array<string, array{0: string, 1: string}> */
    public static function malformedProvider(): array
    {
        return [
            'no such page'                   => [Jbig2Segments::file([]), 'JBIG2 file has no page 1.'],
            'a segment of unknown length'    => [
                Jbig2Segments::file([Jbig2Segments::segment(
                    0,
                    Jbig2Segments::IMMEDIATE_GENERIC_REGION,
                    1,
                    '',
                    length: 0xFFFF_FFFF,
                )]),
                'JBIG2 segments of unknown length are not supported.',
            ],
            'a page with no pixels'          => [
                Jbig2Segments::file([Jbig2Segments::segment(
                    0,
                    Jbig2Segments::PAGE_INFORMATION,
                    1,
                    Jbig2Segments::pageInformation(0, 8),
                )]),
                'JBIG2 page has no pixels.',
            ],
            'a striped page with no stripes' => [
                Jbig2Segments::file([Jbig2Segments::segment(
                    0,
                    Jbig2Segments::PAGE_INFORMATION,
                    1,
                    Jbig2Segments::pageInformation(8, 0xFFFF_FFFF, stripes: 0x8008),
                )]),
                'JBIG2 page has no pixels.',
            ],
            'a segment header cut short'     => [
                Jbig2Segments::file([["\x00\x00\x00\x00\x30", '']]),
                'The JBIG2 data is cut short',
            ],
            'page information cut short'     => [
                Jbig2Segments::file([Jbig2Segments::segment(0, Jbig2Segments::PAGE_INFORMATION, 1, '')]),
                'The JBIG2 page information is cut short',
            ],
        ];
    }

    /** @return array<string, array{0: int}> */
    public static function referredProvider(): array
    {
        return [
            'one-byte numbers, short form' => [9],
            'two-byte numbers, long form'  => [300],
            'four-byte numbers'            => [70_000],
        ];
    }

    /** @return array<string, array{0: int, 1: float}> pixels per metre, and the resolution in dpi */
    public static function resolutionProvider(): array
    {
        return [
            '300 dpi'    => [11_811, 300.0],
            'none given' => [0, 72.0],
        ];
    }

    /** The bar page's segments as PDF takes them: the page information and the region, on page 1. */
    private static function barOnPageOne(): string
    {
        [$info, $region] = Jbig2Segments::barPage();

        return implode('', $info) . implode('', $region);
    }

    /** @param list<array{0: string, 1: string}> $segments */
    private static function decode(array $segments, int $page = 1, ?int $pages = 1): ImageData
    {
        return (new Jbig2Decoder())->decode(
            Jbig2Segments::file($segments, $pages),
            Limits::default(),
            new ImageOptions(page: $page),
        );
    }

    /**
     * A segment with its four-byte page association changed.
     *
     * @param array{0: string, 1: string} $segment
     *
     * @return array{0: string, 1: string}
     */
    private static function withPage(array $segment, string $from, string $to): array
    {
        return [str_replace($from, $to, $segment[0]), $segment[1]];
    }

    #[Test]
    public function aFileWithoutItsNumberOfPagesIsRead(): void
    {
        self::assertSame(self::barOnPageOne(), self::decode(Jbig2Segments::barPage(), pages: null)->raster->data);
    }

    #[Test]
    #[DataProvider('acceptsProvider')]
    public function aJbig2FileIsKnownByItsSignature(string $data, bool $accepted): void
    {
        self::assertSame($accepted, (new Jbig2Decoder())->accepts($data));
    }

    #[Test]
    #[DataProvider('malformedProvider')]
    public function aMalformedFileIsRefused(string $data, string $message): void
    {
        $this->expectException(ParseException::class);
        $this->expectExceptionMessage($message);

        (new Jbig2Decoder())->decode($data, Limits::default(), new ImageOptions());
    }

    #[Test]
    public function aPageIsReadAsOneBitGreyAtThePageInformationsSize(): void
    {
        $raster = self::decode(Jbig2Segments::barPage())->raster;

        self::assertSame(
            [8, 8, 1, 1, 'JBIG2Decode', null],
            [
                $raster->width,
                $raster->height,
                $raster->bitsPerComponent,
                $raster->colorSpace->components,
                $raster->filter,
                $raster->jbig2Globals,
            ],
        );
    }

    #[Test]
    public function aPageNumberPastAByteIsWrittenAsPageOneInFourBytes(): void
    {
        [$info, $region] = Jbig2Segments::barPage(page: 300);
        $data = self::decode(Jbig2Segments::barPage(page: 300), page: 300)->raster->data;

        self::assertSame(
            implode('', self::withPage($info, pack('N', 300), pack('N', 1)))
                . implode('', self::withPage($region, pack('N', 300), pack('N', 1))),
            $data,
        );
    }

    #[Test]
    public function aRandomAccessFileIsReadAsASequentialOneIs(): void
    {
        $data = (new Jbig2Decoder())->decode(
            Jbig2Segments::randomAccessFile(Jbig2Segments::barPage()),
            Limits::default(),
            new ImageOptions(),
        )
            ->raster
            ->data;

        self::assertSame(self::barOnPageOne(), $data);
    }

    #[Test]
    public function aStripedPageOfUnknownHeightEndsAtItsLastStripe(): void
    {
        $image = self::decode([
            Jbig2Segments::segment(
                0,
                Jbig2Segments::PAGE_INFORMATION,
                1,
                Jbig2Segments::pageInformation(8, 0xFFFF_FFFF, stripes: 0x8004),
            ),
            Jbig2Segments::segment(1, Jbig2Segments::END_OF_STRIPE, 1, pack('N', 3)),
            Jbig2Segments::segment(2, Jbig2Segments::END_OF_STRIPE, 1, pack('N', 7)),
        ]);

        self::assertSame(8, $image->raster->height);
    }

    #[Test]
    public function decodersReadEveryPageOfAJbig2File(): void
    {
        self::assertSame([true, 'JBIG2'], [(new Jbig2Decoder())->hasPages(), (new Jbig2Decoder())->name()]);
    }

    #[Test]
    public function onlyTheAskedForPageIsTaken(): void
    {
        [$otherInfo, $otherRegion] = Jbig2Segments::barPage();
        $segments = [
            Jbig2Segments::segment(0, Jbig2Segments::PAGE_INFORMATION, 1, $otherInfo[1]),
            Jbig2Segments::segment(1, Jbig2Segments::IMMEDIATE_GENERIC_REGION, 1, $otherRegion[1]),
            ...Jbig2Segments::barPage(page: 2),
        ];

        self::assertSame(self::barOnPageOne(), self::decode($segments, page: 2, pages: 2)->raster->data);
    }

    #[Test]
    #[DataProvider('referredProvider')]
    public function segmentsReferringToOthersAreReadWhateverTheirNumbering(int $number): void
    {
        $referring = Jbig2Segments::segment(
            $number,
            Jbig2Segments::IMMEDIATE_GENERIC_REGION,
            1,
            Jbig2Segments::bar(),
            referred: [1, 2, 3, 4, 5],
        );
        $segments = [Jbig2Segments::barPage()[0], $referring];

        self::assertSame(
            implode('', Jbig2Segments::barPage()[0]) . implode('', $referring),
            self::decode($segments)->raster->data,
        );
    }

    #[Test]
    public function segmentsSharedByEveryPageBecomeTheGlobals(): void
    {
        $dictionary = Jbig2Segments::segment(4, Jbig2Segments::SYMBOL_DICTIONARY, 0, 'symbols');

        self::assertSame(
            implode('', $dictionary),
            self::decode([$dictionary, ...Jbig2Segments::barPage()])->raster->jbig2Globals,
        );
    }

    #[Test]
    #[DataProvider('resolutionProvider')]
    public function thePageResolutionIsReadInDotsPerInch(int $pixelsPerMetre, float $dpi): void
    {
        $image = self::decode(Jbig2Segments::barPage(resolution: $pixelsPerMetre));

        self::assertEqualsWithDelta([$dpi, $dpi], [$image->dpiX, $image->dpiY], 0.01);
    }
}
