<?php

declare(strict_types=1);

namespace Php2PdfTest\Unit\Core\Filter;

use Php2Pdf\Core\Filter\Flate;
use Php2Pdf\Core\Filter\PngScanlines;
use Php2Pdf\Core\Filter\Predictor;
use Php2Pdf\Core\Limits;
use Php2Pdf\Image\ImageOptions;
use Php2Pdf\Image\PngDecoder;
use Php2PdfTest\TestAsset\Image\PngChunks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function abs;
use function array_fill;
use function chr;
use function count;
use function implode;
use function intdiv;
use function iterator_to_array;
use function ksort;
use function max;
use function mt_rand;
use function mt_srand;
use function ord;
use function pack;
use function sprintf;
use function str_split;
use function substr;
use function unpack;

/**
 * The PNG filters reversed by their specialised loops give the bytes of a plain per-byte reading
 * of the specification, for every filter, pixel size and depth; interlaced images are put
 * together pixel for pixel; a palette's transparency becomes its alpha.
 */
#[Group('unit')]
final class PngUnfilterTest extends TestCase
{
    /** The Adam7 passes: x start, y start, x step, y step. */
    private const array PASSES = [
        [0, 0, 8, 8],
        [4, 0, 8, 8],
        [0, 4, 4, 8],
        [2, 0, 4, 4],
        [0, 2, 2, 4],
        [1, 0, 2, 2],
        [0, 1, 1, 2],
    ];

    /** @return array<string, array{0: int, 1: int, 2: int, 3: int|null}> bit depth, channels, width and filter (null for mixed) */
    public static function formatProvider(): array
    {
        $formats = [];
        foreach ([1, 2, 4, 8, 16] as $depth) {
            foreach ([1, 2, 3, 4] as $channels) {
                foreach ([1, 2, 3, 5, 8, 31] as $width) {
                    foreach ([0, 1, 2, 3, 4, null] as $filter) {
                        $key = sprintf(
                            '%d-bit, %d channels, %d wide, filter %s',
                            $depth,
                            $channels,
                            $width,
                            $filter ?? 'mixed',
                        );
                        $formats[$key] = [$depth, $channels, $width, $filter];
                    }
                }
            }
        }

        return $formats;
    }

    /** @return array<string, array{0: int, 1: int, 2: int, 3: int}> bit depth, channels, width and height */
    public static function interlacedProvider(): array
    {
        $formats = [
            '1-bit grey'    => [1, 1],
            '2-bit grey'    => [2, 1],
            '4-bit grey'    => [4, 1],
            '8-bit grey'    => [8, 1],
            '8-bit RGB'     => [8, 3],
            '8-bit RGBA'    => [8, 4],
            '16-bit grey'   => [16, 1],
            '16-bit RGBA'   => [16, 4],
            '16-bit grey+a' => [16, 2],
        ];
        $cases = [];
        foreach ($formats as $name => [$depth, $channels]) {
            foreach ([[1, 1], [1, 9], [3, 2], [8, 8], [13, 11], [17, 5]] as [$width, $height]) {
                $cases["{$name}, {$width} x {$height}"] = [$depth, $channels, $width, $height];
            }
        }

        return $cases;
    }

    /** @return array<string, array{0: int, 1: int}> colours per pixel and bits per sample */
    public static function tiffPredictorProvider(): array
    {
        $cases = [];
        foreach ([1, 3, 4, 8] as $colors) {
            foreach ([8, 16] as $bits) {
                $cases["{$colors} colours, {$bits}-bit"] = [$colors, $bits];
            }
        }

        return $cases;
    }

    /**
     * Interlaced data the per-pixel way: each pass unfiltered and expanded, its pixels dropped
     * into a grid and the grid's rows joined in x order.
     */
    private static function referenceInterlaced(
        string $raw,
        int $width,
        int $height,
        int $depth,
        int $channels,
        int $factor,
    ): string {
        $pixelBytes = max(1, $channels * ($depth > 8 ? 2 : 1));
        $slots      = array_fill(0, max(0, $height), value: []);
        $offset     = 0;
        foreach (self::PASSES as [$x0, $y0, $dx, $dy]) {
            if ($x0 >= $width || $y0 >= $height) {
                continue;
            }
            $passWidth  = intdiv($width - $x0 + $dx - 1, $dx);
            $passHeight = intdiv($height - $y0 + $dy - 1, $dy);
            $rowBytes   = intdiv(($passWidth * $channels * $depth) + 7, num2: 8);
            $length     = ($rowBytes + 1) * $passHeight;
            $rows       = PngScanlines::decode(
                substr($raw, $offset, $length),
                $passWidth,
                $passHeight,
                $depth,
                $channels,
                interlaced: 0,
                factor: $factor,
            );
            $offset += $length;
            $stride = $passWidth * $pixelBytes;
            for ($y = 0; $y < $passHeight; ++$y) {
                foreach (str_split(substr($rows, $y * $stride, $stride), $pixelBytes) as $x => $pixel) {
                    $slots[$y0 + ($y * $dy)][$x0 + ($x * $dx)] = $pixel;
                }
            }
        }
        $image = '';
        foreach ($slots as $row) {
            ksort($row);
            $image .= implode('', $row);
        }

        return $image;
    }

    /** The Paeth predictor as the PNG specification writes it: of a, b and c, the nearest to a + b - c. */
    private static function referencePaeth(int $a, int $b, int $c): int
    {
        $p  = $a + $b - $c;
        $pa = abs($p - $a);
        $pb = abs($p - $b);
        $pc = abs($p - $c);
        if ($pa <= $pb && $pa <= $pc) {
            return $a;
        }

        return $pb <= $pc ? $b : $c;
    }

    /** Unfiltered rows a byte at a time, as the PNG specification writes the filters. */
    private static function referenceUnfilter(string $raw, int $rowBytes, int $height, int $bpp): string
    {
        $out  = '';
        $prev = array_fill(1, max(0, $rowBytes), value: 0);
        for ($y = 0; $y < $height; ++$y) {
            $start  = $y * ($rowBytes + 1);
            $filter = ord($raw[$start]);
            /** @var array<int, int> $row */
            $row = unpack('C*', substr($raw, $start + 1, $rowBytes));
            for ($i = 1; $i <= $rowBytes; ++$i) {
                $a         = $i > $bpp ? $row[$i - $bpp] ?? 0 : 0;
                $b         = $prev[$i] ?? 0;
                $c         = $i > $bpp ? $prev[$i - $bpp] ?? 0 : 0;
                $predictor = match ($filter) {
                    1       => $a,
                    2       => $b,
                    3       => ($a + $b) >> 1,
                    4       => self::referencePaeth($a, $b, $c),
                    default => 0,
                };
                $row[$i] = (($row[$i] ?? 0) + $predictor) & 0xFF;
            }
            $out  .= pack('C*', ...$row);
            $prev = $row;
        }

        return $out;
    }

    /** Random filtered rows, with runs of equal bytes so every Paeth branch is taken. */
    private static function scanlines(int $rowBytes, int $height, ?int $filter = null): string
    {
        $raw = '';
        for ($y = 0; $y < $height; ++$y) {
            $raw .= chr(
                $filter ?? mt_rand(
                    min: 0,
                    max: 4,
                ),
            );
            for ($i = 0; $i < $rowBytes; ++$i) {
                $raw .= chr(
                    mt_rand(
                        min: 0,
                        max: 2,
                    ) > 0
                        ? mt_rand(
                            min: 0,
                            max: 255,
                        ) : mt_rand(
                            min: 0,
                            max: 1,
                        ) * 255,
                );
            }
        }

        return $raw;
    }

    #[Test]
    #[DataProvider('interlacedProvider')]
    public function anInterlacedImageIsPutTogetherPixelForPixel(
        int $depth,
        int $channels,
        int $width,
        int $height,
    ): void {
        mt_srand(((((($depth * 10) + $channels) * 100) + $width) * 100) + $height);
        $factor = $depth < 8 ? intdiv(255, (1 << $depth) - 1) : 1;
        $raw    = '';
        foreach (self::PASSES as [$x0, $y0, $dx, $dy]) {
            if ($x0 >= $width || $y0 >= $height) {
                continue;
            }
            $passWidth  = intdiv($width - $x0 + $dx - 1, $dx);
            $passHeight = intdiv($height - $y0 + $dy - 1, $dy);
            $raw        .= self::scanlines(intdiv(($passWidth * $channels * $depth) + 7, num2: 8), $passHeight);
        }

        self::assertSame(
            self::referenceInterlaced($raw, $width, $height, $depth, $channels, $factor),
            PngScanlines::decode($raw, $width, $height, $depth, $channels, 1, $factor),
        );
    }

    #[Test]
    public function aPaletteTransparencyBecomesTheAlphaOfEachIndex(): void
    {
        // Two rows of four 2-bit indices: 0 1 2 3, 3 2 1 0; only indices 0 and 1 have a tRNS entry.
        $image = (new PngDecoder())->decode(
            PngChunks::file(
                PngChunks::header(
                    width: 4,
                    height: 2,
                    depth: 2,
                    type: 3,
                ),
                PngChunks::chunk('PLTE', "\x00\x00\x00\x40\x40\x40\x80\x80\x80\xFF\xFF\xFF"),
                PngChunks::chunk('tRNS', "\x00\x7F"),
                PngChunks::imageData("\0\x1B\0\xE4"),
            ),
            Limits::default(),
            new ImageOptions(),
        );

        self::assertSame(
            ["\x00\x01\x02\x03\x03\x02\x01\x00", "\x00\x7F\xFF\xFF\xFF\xFF\x7F\x00", 8],
            [Flate::decode($image->raster->data), $image->softMask?->data, $image->softMask?->bits],
        );
    }

    /** Unfiltering a whole image, reading it a row at a time and the PNG predictor all agree with it. */
    #[Test]
    #[DataProvider('formatProvider')]
    public function everyFilterGivesTheBytesOfThePerByteReading(
        int $depth,
        int $channels,
        int $width,
        ?int $filter,
    ): void {
        mt_srand(((((($depth * 10) + $channels) * 100) + $width) * 10) + ($filter ?? 9));
        $bpp      = max(1, intdiv($channels * $depth, num2: 8));
        $rowBytes = intdiv(($width * $channels * $depth) + 7, num2: 8);
        $raw      = self::scanlines($rowBytes, 6, $filter);
        $expected = self::referenceUnfilter($raw, $rowBytes, 6, $bpp);

        self::assertSame(
            [$expected, str_split($expected, max(1, $rowBytes)), $expected],
            [
                PngScanlines::unfilter($raw, $width, 6, $depth, $channels),
                iterator_to_array(
                    PngScanlines::rows(
                        str_split(Flate::encode($raw), length: 5),
                        $width,
                        6,
                        $depth,
                        $channels,
                        1 << 20,
                    ),
                    preserve_keys: false,
                ),
                Predictor::decode($raw, 15, $channels, $depth, $width),
            ],
        );
    }

    #[Test]
    #[DataProvider('tiffPredictorProvider')]
    public function theTiffPredictorAddsEachSampleToTheOneOfItsColourBefore(int $colors, int $bits): void
    {
        mt_srand(($colors * 100) + $bits);
        $columns = 7;
        $data    = '';
        for ($i = 0; $i < ($columns * $colors * 6); ++$i) {
            $data .= chr(mt_rand(
                min: 0,
                max: 255,
            ));
        }
        $format   = 8 === $bits ? 'C*' : 'n*';
        $mask     = 8 === $bits ? 0xFF : 0xFFFF;
        $expected = '';
        foreach (str_split($data, max(1, ($columns * $colors * $bits) >> 3)) as $line) {
            /** @var array<int, int> $row */
            $row   = unpack($format, $line);
            $count = count($row);
            for ($i = $colors + 1; $i <= $count; ++$i) {
                $row[$i] = (($row[$i] ?? 0) + ($row[$i - $colors] ?? 0)) & $mask;
            }
            $expected .= pack($format, ...$row);
        }

        self::assertSame($expected, Predictor::decode($data, 2, $colors, $bits, $columns));
    }
}
