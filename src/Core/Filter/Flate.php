<?php

declare(strict_types=1);

namespace PhpPdf\Core\Filter;

use PhpPdf\Exception\PdfException;

final class Flate
{
    public static function encode(string $data, int $level = 6): string
    {
        $out = gzcompress($data, $level);
        if ($out === false) {
            throw new PdfException('Flate compression failed.');
        }

        return $out;
    }

    public static function decode(string $data): string
    {
        $out = @gzuncompress($data);
        if ($out === false) {
            // Some producers write raw deflate without the zlib header, or trailing garbage.
            $out = @gzinflate($data);
            if ($out === false) {
                $out = @gzinflate(substr($data, 2));
            }
            if ($out === false) {
                throw new PdfException('Flate decompression failed.');
            }
        }

        return $out;
    }
}
