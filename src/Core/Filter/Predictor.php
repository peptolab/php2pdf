<?php

declare(strict_types=1);

namespace Php2Pdf\Core\Filter;

use Php2Pdf\Exception\ParseException;

use function array_key_exists;
use function intdiv;
use function pack;
use function sprintf;
use function strlen;
use function substr;
use function unpack;

/**
 * Undoes the TIFF (2) and PNG (10-15) predictors used with Flate and LZW.
 *
 * @internal
 */
final class Predictor
{
    /** The bit depths samples come in, as keys. */
    private const array BITS = [1 => true, 2 => true, 4 => true, 8 => true, 16 => true];

    /** @throws ParseException */
    public static function decode(
        string $data,
        int $predictor,
        int $colors,
        int $bitsPerComponent,
        int $columns,
    ): string {
        if ($predictor <= 1) {
            return $data;
        }
        $depth = array_key_exists($bitsPerComponent, self::BITS);
        if ($columns < 1 || $colors < 1 || $colors > 32 || ! $depth) {
            throw new ParseException(sprintf(
                'Invalid predictor parameters: %d columns, %d colours, %d bits per component.',
                $columns,
                $colors,
                $bitsPerComponent,
            ));
        }
        if ($predictor >= 10) {
            $rowBytes = intdiv(($columns * $colors * $bitsPerComponent) + 7, num2: 8);
            $rows     = intdiv(strlen($data), $rowBytes + 1);

            return PngScanlines::unfilter(
                substr($data, offset: 0, length: $rows * ($rowBytes + 1)),
                $columns,
                $rows,
                $bitsPerComponent,
                $colors,
            );
        }
        if (2 === $predictor) {
            return 16 === $bitsPerComponent
                ? self::tiff16($data, $colors, $columns, 'n')
                : self::tiff($data, $colors, $bitsPerComponent, $columns);
        }
        throw new ParseException(sprintf('Unsupported predictor %d.', $predictor));
    }

    /**
     * The TIFF predictor over 16-bit samples: each sample is the difference from the one of its
     * colour before it in the row, modulo 65536, in the data's own byte order: big-endian in PDF,
     * either in TIFF.
     *
     * @param 'v'|'n' $format the samples' byte order, as unpack() names it
     *
     * @throws ParseException
     */
    public static function tiff16(string $data, int $colors, int $columns, string $format): string
    {
        $count    = $columns * $colors;
        $rowBytes = $count * 2;
        $rows     = intdiv(strlen($data), $rowBytes);
        $out      = '';
        for ($r = 0; $r < $rows; ++$r) {
            /** @var array<int, int> $row 1-based */
            $row = unpack("{$format}*", substr($data, $r * $rowBytes, $rowBytes));
            for ($k = 1; $k <= $colors; ++$k) {
                $a = $row[$k] ?? 0;
                for ($i = $k + $colors; $i <= $count; $i += $colors) {
                    $a       = (($row[$i] ?? 0) + $a) & 0xFFFF;
                    $row[$i] = $a;
                }
            }
            $out .= pack("{$format}*", ...$row);
        }

        return $out;
    }

    /** @throws ParseException */
    private static function tiff(string $data, int $colors, int $bitsPerComponent, int $columns): string
    {
        if (8 !== $bitsPerComponent) {
            throw new ParseException('TIFF predictor is only supported for 8 and 16 bits per component.');
        }
        $rowBytes = $columns * $colors;
        $rows     = intdiv(strlen($data), $rowBytes);
        $out      = '';
        for ($r = 0; $r < $rows; ++$r) {
            // The same sum as PNG's Sub filter, with a colour's samples as the pixel.
            $out .= PngScanlines::sub(substr($data, $r * $rowBytes, $rowBytes), $colors);
        }

        return $out;
    }
}
