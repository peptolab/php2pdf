<?php

declare(strict_types=1);

namespace Php2Pdf\Core\Filter;

use Php2Pdf\Exception\ParseException;
use Php2Pdf\Image\PngScanlines;

use function in_array;
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
        if (
            $columns < 1
            || $colors < 1
            || $colors > 32
            || ! in_array($bitsPerComponent, [1, 2, 4, 8, 16], strict: true)
        ) {
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
            return self::tiff($data, $colors, $bitsPerComponent, $columns);
        }
        throw new ParseException(sprintf('Unsupported predictor %d.', $predictor));
    }

    private static function tiff(string $data, int $colors, int $bitsPerComponent, int $columns): string
    {
        if (8 !== $bitsPerComponent) {
            throw new ParseException('TIFF predictor is only supported for 8 bits per component.');
        }
        $rowBytes = $columns * $colors;
        $rows     = intdiv(strlen($data), $rowBytes);
        $out      = '';
        for ($r = 0; $r < $rows; ++$r) {
            /** @var array<int, int>|false $row 1-based */
            $row = unpack('C*', substr($data, $r * $rowBytes, $rowBytes));
            if (false === $row) {
                throw new ParseException('Predictor row could not be read.');
            }
            for ($i = $colors + 1; $i <= $rowBytes; ++$i) {
                $row[$i] = (($row[$i] ?? 0) + ($row[$i - $colors] ?? 0)) & 0xFF;
            }
            $out .= pack('C*', ...$row);
        }

        return $out;
    }
}
