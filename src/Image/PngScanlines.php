<?php

declare(strict_types=1);

namespace PhpPdf\Image;

use PhpPdf\Exception\PdfException;

use function abs;
use function chr;
use function count;
use function intdiv;
use function max;
use function ord;
use function pack;
use function sprintf;
use function str_repeat;
use function strlen;
use function substr;
use function substr_replace;
use function unpack;

/**
 * Reverses PNG scanline filtering and Adam7 interlacing, producing one byte per sample.
 *
 * Rows are handled as 1-based integer arrays straight from unpack(), which avoids re-indexing
 * every row.
 *
 * @internal
 */
final class PngScanlines
{
    /** Adam7 passes: x start, y start, x step, y step. */
    private const array PASSES = [
        [0, 0, 8, 8],
        [4, 0, 8, 8],
        [0, 4, 4, 8],
        [2, 0, 4, 4],
        [0, 2, 2, 4],
        [1, 0, 2, 2],
        [0, 1, 1, 2],
    ];

    /**
     * Decodes the inflated IDAT stream into unfiltered rows. Samples narrower than a byte are
     * expanded to one byte each; 16-bit samples are kept as two bytes.
     *
     * @param int $factor multiplier for expanded sub-byte samples (255 / max value for gray levels, 1 for indices)
     *
     * @return string width * height * channels * bytesPerSample bytes
     */
    public static function decode(
        string $inflated,
        int $width,
        int $height,
        int $bitDepth,
        int $channels,
        int $interlaced,
        int $factor = 1,
    ): string {
        if (0 === $interlaced) {
            $rows = self::unfilter($inflated, $width, $height, $bitDepth, $channels);

            return self::expand($rows, $width, $height, $bitDepth, $channels, $factor);
        }

        $pixelBytes = max(1, $channels * ($bitDepth > 8 ? 2 : 1));
        $image      = str_repeat("\0", max(0, $width * $height * $pixelBytes));
        $offset     = 0;
        foreach (self::PASSES as [$x0, $y0, $dx, $dy]) {
            if ($x0 >= $width || $y0 >= $height) {
                continue;
            }
            $passWidth  = intdiv($width - $x0 + $dx - 1, $dx);
            $passHeight = intdiv($height - $y0 + $dy - 1, $dy);
            $rowBytes   = intdiv(($passWidth * $channels * $bitDepth) + 7, num2: 8);
            $passLength = ($rowBytes + 1) * $passHeight;
            $rows       = self::unfilter(
                substr($inflated, $offset, $passLength),
                $passWidth,
                $passHeight,
                $bitDepth,
                $channels,
            );
            $rows   = self::expand($rows, $passWidth, $passHeight, $bitDepth, $channels, $factor);
            $offset += $passLength;
            for ($y = 0; $y < $passHeight; ++$y) {
                $target = ($y0 + ($y * $dy)) * $width;
                $source = $y * $passWidth * $pixelBytes;
                for ($x = 0; $x < $passWidth; ++$x) {
                    $image = substr_replace(
                        $image,
                        substr($rows, $source + ($x * $pixelBytes), $pixelBytes),
                        ($target + $x0 + ($x * $dx)) * $pixelBytes,
                        $pixelBytes,
                    );
                }
            }
        }

        return $image;
    }

    /**
     * Reverses the per-row filters. Rows keep their packed layout.
     */
    public static function unfilter(string $raw, int $width, int $height, int $bitDepth, int $channels): string
    {
        $rowBytes = intdiv(($width * $channels * $bitDepth) + 7, num2: 8);
        $bpp      = max(1, intdiv($channels * $bitDepth, num2: 8));
        if (strlen($raw) < (($rowBytes + 1) * $height)) {
            throw new PdfException('PNG image data is truncated.');
        }
        $out  = '';
        $prev = [];
        for ($y = 0; $y < $height; ++$y) {
            $start  = $y * ($rowBytes + 1);
            $filter = ord($raw[$start]);
            /** @var array<int, int>|false $row */
            $row = unpack('C*', substr($raw, $start + 1, $rowBytes));
            if (false === $row) {
                throw new PdfException('PNG scanline could not be read.');
            }
            $row = match ($filter) {
                0       => $row,
                1       => self::sub($row, $bpp),
                2       => self::up($row, $prev),
                3       => self::average($row, $prev, $bpp),
                4       => self::paeth($row, $prev, $bpp),
                default => throw new PdfException(sprintf('Unknown PNG filter type %d.', $filter)),
            };
            $out  .= pack('C*', ...$row);
            $prev = $row;
        }

        return $out;
    }

    /**
     * @param array<int, int> $row  1-based
     * @param array<int, int> $prev 1-based
     *
     * @return array<int, int>
     */
    private static function average(array $row, array $prev, int $bpp): array
    {
        $n = count($row);
        for ($i = 1; $i <= $n; ++$i) {
            $left    = $i > $bpp ? $row[$i - $bpp] ?? 0 : 0;
            $row[$i] = (($row[$i] ?? 0) + (($left + ($prev[$i] ?? 0)) >> 1)) & 0xFF;
        }

        return $row;
    }

    /**
     * Expands sub-byte samples to one byte each, multiplying by $factor.
     */
    private static function expand(
        string $rows,
        int $width,
        int $height,
        int $bitDepth,
        int $channels,
        int $factor,
    ): string {
        if ($bitDepth >= 8) {
            return $rows;
        }
        $rowBytes = intdiv(($width * $channels * $bitDepth) + 7, num2: 8);
        $samples  = $width * $channels;
        $perByte  = intdiv(8, $bitDepth);
        $mask     = (1 << $bitDepth) - 1;
        $out      = '';
        for ($y = 0; $y < $height; ++$y) {
            $row = substr($rows, $y * $rowBytes, $rowBytes);
            for ($i = 0; $i < $samples; ++$i) {
                $byte  = ord($row[intdiv($i, $perByte)]);
                $shift = 8 - ($bitDepth * (($i % $perByte) + 1));
                $out   .= chr((($byte >> $shift) & $mask) * $factor);
            }
        }

        return $out;
    }

    /**
     * @param array<int, int> $row  1-based
     * @param array<int, int> $prev 1-based
     *
     * @return array<int, int>
     */
    private static function paeth(array $row, array $prev, int $bpp): array
    {
        $n = count($row);
        for ($i = 1; $i <= $n; ++$i) {
            $a         = $i > $bpp ? $row[$i - $bpp] ?? 0 : 0;
            $b         = $prev[$i] ?? 0;
            $c         = $i > $bpp ? $prev[$i - $bpp] ?? 0 : 0;
            $p         = $a + $b - $c;
            $pa        = abs($p - $a);
            $pb        = abs($p - $b);
            $pc        = abs($p - $c);
            $predictor = $pb <= $pc ? $b : $c;
            if ($pa <= $pb && $pa <= $pc) {
                $predictor = $a;
            }
            $row[$i] = (($row[$i] ?? 0) + $predictor) & 0xFF;
        }

        return $row;
    }

    /**
     * @param array<int, int> $row 1-based
     *
     * @return array<int, int>
     */
    private static function sub(array $row, int $bpp): array
    {
        $n = count($row);
        for ($i = $bpp + 1; $i <= $n; ++$i) {
            $row[$i] = (($row[$i] ?? 0) + ($row[$i - $bpp] ?? 0)) & 0xFF;
        }

        return $row;
    }

    /**
     * @param array<int, int> $row  1-based
     * @param array<int, int> $prev 1-based
     *
     * @return array<int, int>
     */
    private static function up(array $row, array $prev): array
    {
        foreach ($row as $i => $v) {
            $row[$i] = ($v + ($prev[$i] ?? 0)) & 0xFF;
        }

        return $row;
    }
}
