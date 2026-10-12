<?php

declare(strict_types=1);

namespace Php2Pdf\Core\Filter;

use Generator;
use InflateContext;
use Php2Pdf\Core\Silencer;
use Php2Pdf\Exception\ParseException;

use function array_combine;
use function array_fill;
use function array_replace;
use function chr;
use function implode;
use function inflate_add;
use function inflate_init;
use function intdiv;
use function max;
use function min;
use function ord;
use function pack;
use function sprintf;
use function str_repeat;
use function str_split;
use function strlen;
use function strtr;
use function substr;
use function unpack;

use const ZLIB_ENCODING_DEFLATE;
use const ZLIB_SYNC_FLUSH;

/**
 * Reverses PNG scanline filtering and Adam7 interlacing, producing one byte per sample.
 *
 * Each filter has its own loop. Rows are handled as 1-based integer arrays straight from
 * unpack(), walked one channel at a time so the bytes to the left and above-left stay in locals.
 * Where a pixel is a whole number of 32-bit words, or for Up, the bytes are added four at a time
 * in one integer with the carries masked off (SWAR): 32-bit words, as 64-bit sums would overflow
 * into floats.
 *
 * @internal
 */
final class PngScanlines
{
    /** The low seven bits of each byte of a 32-bit word. */
    private const int LOW = 0x7F7F7F7F;

    /** The top bit of each byte of a 32-bit word. */
    private const int HIGH = 0x80808080;

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
     *
     * @throws ParseException
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

        // Each pass unfiltered and expanded whole, then every image row put together from the pass
        // rows that hold its pixels: a row of pixel strings keyed in x order, the passes' pixels
        // replaced into it, joined.
        $pixelBytes = max(1, $channels * ($bitDepth > 8 ? 2 : 1));
        $passes     = [];
        $offset     = 0;
        foreach (self::PASSES as $pass => [$x0, $y0, $dx, $dy]) {
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
            $offset += $passLength;
            $xs     = [];
            for ($x = $x0; $x < $width; $x += $dx) {
                $xs[] = $x;
            }
            $passes[$pass] = [
                self::expand($rows, $passWidth, $passHeight, $bitDepth, $channels, $factor),
                $passWidth * $pixelBytes,
                $xs,
            ];
        }
        $blank = array_fill(0, $width, '');
        $image = '';
        for ($y = 0; $y < $height; ++$y) {
            if (1 === ($y & 1) && isset($passes[6])) {
                [$rows, $stride] = $passes[6];
                $image .= substr($rows, (($y - 1) >> 1) * $stride, $stride);
                continue;
            }
            $row = $blank;
            foreach ($passes as $pass => [$rows, $stride, $xs]) {
                [, $y0, , $dy] = self::PASSES[$pass];
                if ($y < $y0 || 0 !== (($y - $y0) % $dy)) {
                    continue;
                }
                $pixels = str_split(substr($rows, intdiv($y - $y0, $dy) * $stride, $stride), $pixelBytes);
                $row    = array_replace($row, array_combine($xs, $pixels));
            }
            $image .= implode('', $row);
        }

        return $image;
    }

    /**
     * The rows of a non-interlaced image, unfiltered, one at a time, from its compressed data in
     * pieces (a PNG's IDAT chunks): the data is inflated as the rows are needed, so the whole
     * image is never held at once.
     *
     * @param iterable<string> $compressed
     *
     * @return Generator<int, string>
     *
     * @throws ParseException for data that does not inflate, decodes to more than $maxBytes, or
     *                        ends before the last row
     */
    public static function rows(
        iterable $compressed,
        int $width,
        int $height,
        int $bitDepth,
        int $channels,
        int $maxBytes,
    ): Generator {
        $rowBytes = intdiv(($width * $channels * $bitDepth) + 7, num2: 8);
        $bpp      = max(1, intdiv($channels * $bitDepth, num2: 8));
        // zlib fails to start only when it cannot allocate its state.
        /** @var InflateContext $inflater */
        $inflater = Silencer::run(static fn(): InflateContext|false => inflate_init(ZLIB_ENCODING_DEFLATE));
        $buffer   = '';
        $decoded  = 0;
        $y        = 0;
        $prev     = str_repeat("\0", $rowBytes);
        foreach ($compressed as $piece) {
            $out = Silencer::run(static fn(): string|false => inflate_add($inflater, $piece, ZLIB_SYNC_FLUSH));
            if (false === $out) {
                throw new ParseException('Flate decompression failed.');
            }
            $decoded += strlen($out);
            if ($decoded > $maxBytes) {
                throw new ParseException(sprintf('A compressed stream decodes to more than %d bytes.', $maxBytes));
            }
            $buffer .= $out;
            $offset = 0;
            while ($y < $height && (strlen($buffer) - $offset) >= ($rowBytes + 1)) {
                $prev = self::unfilterRow(ord($buffer[$offset]), substr($buffer, $offset + 1, $rowBytes), $prev, $bpp);
                yield $y => $prev;
                $offset += $rowBytes + 1;
                ++$y;
            }
            $buffer = substr($buffer, $offset);
        }
        if ($y < $height) {
            throw new ParseException('PNG image data is truncated.');
        }
    }

    /**
     * Reverses the per-row filters. Rows keep their packed layout.
     *
     * @throws ParseException
     */
    public static function unfilter(string $raw, int $width, int $height, int $bitDepth, int $channels): string
    {
        $rowBytes = intdiv(($width * $channels * $bitDepth) + 7, num2: 8);
        $bpp      = max(1, intdiv($channels * $bitDepth, num2: 8));
        if (strlen($raw) < (($rowBytes + 1) * $height)) {
            throw new ParseException('PNG image data is truncated.');
        }
        $out  = '';
        $prev = str_repeat("\0", $rowBytes);
        for ($y = 0; $y < $height; ++$y) {
            $start = $y * ($rowBytes + 1);
            $prev  = self::unfilterRow(ord($raw[$start]), substr($raw, $start + 1, $rowBytes), $prev, $bpp);
            $out  .= $prev;
        }

        return $out;
    }

    /**
     * Each byte plus the byte $bpp before it, a channel at a time, from the second pixel on: the
     * TIFF predictor and PNG's Sub filter.
     */
    public static function sub(string $line, int $bpp): string
    {
        $n = strlen($line);
        if ($n <= $bpp) {
            // One pixel or less: nothing to its left.
            return $line;
        }
        if (0 === ($bpp & 3) && 0 === ($n & 3)) {
            return self::subWords($line, $bpp >> 2);
        }
        /** @var array<int, int> $row */
        $row = unpack('C*', $line);
        for ($k = 1; $k <= $bpp; ++$k) {
            $a = $row[$k];
            for ($i = $k + $bpp; $i <= $n; $i += $bpp) {
                $a = $row[$i] = ($row[$i] + $a) & 0xFF;
            }
        }

        return pack('C*', ...$row);
    }

    /**
     * The Average filter reversed: each byte plus the mean of the byte to its left and the byte
     * above.
     */
    private static function average(string $line, string $prev, int $bpp): string
    {
        $n = strlen($line);
        if (0 === ($bpp & 3) && 0 === ($n & 3)) {
            return self::averageWords($line, $prev, $bpp >> 2);
        }
        /** @var array<int, int> $row */
        $row = unpack('C*', $line);
        /** @var array<int, int> $up */
        $up  = unpack('C*', $prev);
        $bpp = min($bpp, $n);
        for ($k = 1; $k <= $bpp; ++$k) {
            $a = $row[$k] = ($row[$k] + ($up[$k] >> 1)) & 0xFF;
            for ($i = $k + $bpp; $i <= $n; $i += $bpp) {
                $a = $row[$i] = ($row[$i] + (($a + $up[$i]) >> 1)) & 0xFF;
            }
        }

        return pack('C*', ...$row);
    }

    /**
     * The Average filter reversed four bytes at a time, for pixels of $words 32-bit words. The
     * per-byte floor mean of a and b is (a & b) + ((a ^ b) >> 1), which cannot carry.
     */
    private static function averageWords(string $line, string $prev, int $words): string
    {
        /** @var array<int, int> $row */
        $row = unpack('N*', $line);
        /** @var array<int, int> $up */
        $up = unpack('N*', $prev);
        $n  = strlen($line) >> 2;
        for ($k = 1; $k <= $words; ++$k) {
            $v = $row[$k];
            $m = ($up[$k] >> 1) & self::LOW;
            $a = $row[$k] = (($v & self::LOW) + ($m & self::LOW)) ^ (($v ^ $m) & self::HIGH);
            for ($i = $k + $words; $i <= $n; $i += $words) {
                $b = $up[$i];
                $m = ($a & $b) + ((($a ^ $b) >> 1) & self::LOW);
                $v = $row[$i];
                $a = $row[$i] = (($v & self::LOW) + ($m & self::LOW)) ^ (($v ^ $m) & self::HIGH);
            }
        }

        return pack('N*', ...$row);
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
        $table    = self::expansionTable($bitDepth, $factor);
        $out      = '';
        for ($y = 0; $y < $height; ++$y) {
            $out .= substr(strtr(substr($rows, $y * $rowBytes, $rowBytes), $table), offset: 0, length: $samples);
        }

        return $out;
    }

    /**
     * Each possible byte mapped to its sub-byte samples, one byte per sample, multiplied by $factor.
     *
     * @return array<string, string>
     */
    private static function expansionTable(int $bitDepth, int $factor): array
    {
        $perByte = intdiv(8, $bitDepth);
        $mask    = (1 << $bitDepth) - 1;
        $table   = [];
        for ($byte = 0; $byte < 256; ++$byte) {
            $samples = '';
            for ($i = 0; $i < $perByte; ++$i) {
                $samples .= chr((($byte >> (8 - ($bitDepth * ($i + 1)))) & $mask) * $factor);
            }
            $table[chr($byte)] = $samples;
        }

        return $table;
    }

    /**
     * The Paeth filter reversed. Of left (a), above (b) and above-left (c), the predictor is the
     * one nearest a + b - c, ties going to a, then b; the distances are |b - c|, |a - c| and
     * |a + b - 2c|, made positive with sign tests.
     */
    private static function paeth(string $line, string $prev, int $bpp): string
    {
        /** @var array<int, int> $row */
        $row = unpack('C*', $line);
        /** @var array<int, int> $up */
        $up  = unpack('C*', $prev);
        $n   = strlen($line);
        $bpp = min($bpp, $n);
        for ($k = 1; $k <= $bpp; ++$k) {
            // The first pixel has no left or above-left: its predictor is the byte above.
            $c = $up[$k];
            $a = $row[$k] = ($row[$k] + $c) & 0xFF;
            for ($i = $k + $bpp; $i <= $n; $i += $bpp) {
                $b  = $up[$i];
                $pa = $b - $c;
                // With b equal to c, a is exactly a + b - c: the left byte is the predictor.
                if (0 !== $pa) {
                    $pb = $a - $c;
                    $pc = $pa + $pb;
                    if ($pa < 0) {
                        $pa = -$pa;
                    }
                    if ($pb < 0) {
                        $pb = -$pb;
                    }
                    if ($pc < 0) {
                        $pc = -$pc;
                    }
                    if ($pa > $pb || $pa > $pc) {
                        $a = $pb <= $pc ? $b : $c;
                    }
                }
                $a = $row[$i] = ($row[$i] + $a) & 0xFF;
                $c = $b;
            }
        }

        return pack('C*', ...$row);
    }

    /** Sub four bytes at a time, for pixels of $words 32-bit words. */
    private static function subWords(string $line, int $words): string
    {
        /** @var array<int, int> $row */
        $row = unpack('N*', $line);
        $n   = strlen($line) >> 2;
        for ($k = 1; $k <= $words; ++$k) {
            $a = $row[$k];
            for ($i = $k + $words; $i <= $n; $i += $words) {
                $v = $row[$i];
                $a = $row[$i] = (($v & self::LOW) + ($a & self::LOW)) ^ (($v ^ $a) & self::HIGH);
            }
        }

        return pack('N*', ...$row);
    }

    /**
     * One row with its filter reversed.
     *
     * @param string $prev the row before it unfiltered, or zeros for the first row
     *
     * @throws ParseException
     */
    private static function unfilterRow(int $filter, string $line, string $prev, int $bpp): string
    {
        return match ($filter) {
            0       => $line,
            1       => self::sub($line, $bpp),
            2       => self::up($line, $prev),
            3       => self::average($line, $prev, $bpp),
            4       => self::paeth($line, $prev, $bpp),
            default => throw new ParseException(sprintf('Unknown PNG filter type %d.', $filter)),
        };
    }

    /**
     * The Up filter reversed: each byte plus the byte above, four bytes at a time. A row shorter
     * than eight bytes (a cross-reference stream's, say) is added in one go as a single integer
     * whose top byte is zero, so its sum stays under 2^57.
     */
    private static function up(string $line, string $prev): string
    {
        $n = strlen($line);
        if ($n < 8) {
            $zeros = substr("\0\0\0\0\0\0\0\0", $n);
            $v     = (int) unpack('J', $zeros . $line)[1];
            $b     = (int) unpack('J', $zeros . $prev)[1];
            $v     = (($v & 0x7F7F7F7F7F7F7F) + ($b & 0x7F7F7F7F7F7F7F)) ^ (($v ^ $b) & 0x80808080808080);

            return substr(pack('J', $v), 8 - $n);
        }
        $pad = -$n & 3;
        if (0 !== $pad) {
            $line .= str_repeat("\0", $pad);
            $prev .= str_repeat("\0", $pad);
        }
        /** @var array<int, int> $row */
        $row = unpack('N*', $line);
        /** @var array<int, int> $up */
        $up = unpack('N*', $prev);
        foreach ($row as $i => $v) {
            $b       = $up[$i];
            $row[$i] = (($v & self::LOW) + ($b & self::LOW)) ^ (($v ^ $b) & self::HIGH);
        }
        $out = pack('N*', ...$row);

        return 0 === $pad ? $out : substr($out, offset: 0, length: $n);
    }
}
