<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Core;

use Php2Pdf\Core\Filter\FilterChain;
use Php2Pdf\Core\Filter\Flate;
use Php2Pdf\Core\Filter\Lzw;
use Php2Pdf\Core\Limits;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Output\MemoryOutput;
use Php2Pdf\Document\Document;
use Php2Pdf\Document\DocumentOptions;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Exception\PdfException;
use Php2Pdf\Image\ImageReader;
use Php2Pdf\Import\PdfFile;
use Php2Pdf\Svg\NativeConverter;
use Php2Pdf\Svg\SvgFile;
use Php2PdfTest\Support\HostileFiles;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function gzcompress;
use function gzdeflate;
use function gzencode;
use function str_repeat;

/**
 * Small hostile files fail fast with a PdfException instead of exhausting memory or time
 * (the cases reported in #14).
 */
#[Group('unit')]
final class LimitsTest extends TestCase
{
    /** @return array<string, array{0: string, 1: string}> */
    public static function badPngProvider(): array
    {
        return [
            'depth for colour type' => [HostileFiles::png(1, 1, 4, 2), 'colour type 2 cannot have a bit depth of 4'],
            'unknown interlace'     => [HostileFiles::png(1, 1, 8, 2, 2), 'unknown compression, filter or interlace'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function badWidthsProvider(): array
    {
        return [
            'all zero'        => ['[0 0 0]'],
            'no offset field' => ['[1 0 2]'],
            'field too wide'  => ['[1 9 2]'],
        ];
    }

    /** @return array<string, array{0: string}> */
    public static function flateProvider(): array
    {
        $data = str_repeat('php2pdf ', times: 1000);

        return [
            'zlib'                   => [(string) gzcompress($data)],
            'raw deflate'            => [(string) gzdeflate($data)],
            'raw after two bytes'    => ['xx' . gzdeflate($data)],
            'zlib and trailing junk' => [gzcompress($data) . 'junk'],
        ];
    }

    /** @return array<string, array{0: int, 1: int, 2: int}> */
    public static function predictorProvider(): array
    {
        return [
            'no columns'    => [0, 1, 8],
            'no colours'    => [1, 0, 8],
            'odd bit depth' => [1, 1, 3],
        ];
    }

    #[Test]
    public function aCrossReferenceTableOverTheLimitIsRepairedAround(): void
    {
        $pdf = HostileFiles::onePageUnlessRepaired();

        self::assertSame(
            [1, 2],
            [
                PdfFile::fromData($pdf)->pageCount(),
                PdfFile::fromData($pdf, new Limits(maxXrefEntries: 2))->pageCount(),
            ],
            'Over the limit the table is not read, so the reader repairs and sees the later page tree.',
        );
    }

    #[Test]
    public function aDocumentOverThePageLimitIsRejected(): void
    {
        $file = PdfFile::fromData(HostileFiles::pages(4), new Limits(maxPages: 3));

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('more than 3 pages');
        $file->pageCount();
    }

    #[Test]
    public function aFlateBombStopsAtTheLimit(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('decodes to more than 1000000 bytes');
        Flate::decode((string) gzcompress(str_repeat("\0", times: 20_000_000)), 1_000_000);
    }

    #[Test]
    public function anImageOverThePixelLimitIsRejectedBeforeDecoding(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('60000 by 60000 pixels');
        ImageReader::read(HostileFiles::png(60_000, 60_000, 8, 2, 1));
    }

    #[Test]
    public function anImportedFlateBombStopsAtTheLimit(): void
    {
        $file = PdfFile::fromData(HostileFiles::flateBomb(2_000_000), new Limits(maxDecodedBytes: 1_000_000));

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('decodes to more than 1000000 bytes');
        $file->page(1)->content();
    }

    #[Test]
    public function anLzwStreamStopsAtTheLimit(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('decodes to more than 3 bytes');
        Lzw::decode("\x80\x0B\x60\x50\x22\x0C\x0C\x85\x01", 1, 3);
    }

    #[Test]
    public function anSvgThatExpandsTooManyUsesIsRejected(): void
    {
        $doc = new Document(new MemoryOutput());
        $svg = SvgFile::fromData(HostileFiles::useBomb(20), null, new Limits(maxSvgUses: 100));

        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('more than 100 <use> references');
        $doc->svg($svg, new NativeConverter());
    }

    #[Test]
    public function aSharedPageTreeIsWalkedOnce(): void
    {
        self::assertSame(1, PdfFile::fromData(HostileFiles::sharedPageTree(30))->pageCount());
    }

    #[Test]
    public function deeplyNestedSvgIsRejected(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('nested more than 256 deep');
        SvgFile::fromData(HostileFiles::deepSvg(30_000))->root();
    }

    #[Test]
    public function documentsUseTheDefaultLimitsUnlessGiven(): void
    {
        $limits = new Limits(maxPages: 5);

        self::assertSame(
            [Limits::default(), $limits],
            [(new DocumentOptions())->limits, (new DocumentOptions(limits: $limits))->limits],
        );
    }

    #[Test]
    public function flateRejectsDataThatIsNotCompressed(): void
    {
        $this->expectException(PdfException::class);
        Flate::decode('not compressed at all');
    }

    #[Test]
    #[DataProvider('flateProvider')]
    public function flateStillInflatesEveryVariantProducersWrite(string $compressed): void
    {
        self::assertSame(str_repeat('php2pdf ', times: 1000), Flate::decode($compressed));
    }

    #[Test]
    #[DataProvider('badPngProvider')]
    public function inconsistentPngHeadersAreRejected(string $png, string $message): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage($message);
        ImageReader::read($png);
    }

    #[Test]
    #[DataProvider('predictorProvider')]
    public function invalidPredictorParametersAreRejected(int $columns, int $colors, int $bits): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('Invalid predictor parameters');
        FilterChain::decode(
            (string) gzcompress("\0\0\0\0"),
            ['FlateDecode'],
            [Dictionary::of([
                'Predictor'        => 12,
                'Columns'          => $columns,
                'Colors'           => $colors,
                'BitsPerComponent' => $bits,
            ])],
        );
    }

    #[Test]
    public function limitsMustBePositive(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Limits(maxPages: 0);
    }

    #[Test]
    public function otherImageFormatsAreCheckedAgainstThePixelLimitBeforeTranscoding(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('60000 by 60000 pixels');
        ImageReader::read(HostileFiles::gif(60_000, 60_000));
    }

    #[Test]
    public function svgzThatDecompressesPastTheLimitIsRejected(): void
    {
        $this->expectException(PdfException::class);
        $this->expectExceptionMessage('more than 1000 bytes');
        SvgFile::fromData(
            (string) gzencode('<svg>' . str_repeat(' ', times: 5000) . '</svg>'),
            null,
            new Limits(maxDecodedBytes: 1000),
        );
    }

    /**
     * Unusable /W widths make the reader rebuild the cross-reference from the file instead of
     * looping over a hundred million empty entries.
     */
    #[Test]
    #[DataProvider('badWidthsProvider')]
    public function unusableCrossReferenceStreamWidthsAreRepairedAround(string $widths): void
    {
        self::assertSame(0, PdfFile::fromData(HostileFiles::xrefStream($widths, 100_000_000))->pageCount());
    }
}
