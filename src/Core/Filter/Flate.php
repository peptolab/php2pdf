<?php

declare(strict_types=1);

namespace PhpPdf\Core\Filter;

use PhpPdf\Core\Silencer;
use PhpPdf\Exception\PdfException;

use function gzcompress;
use function gzinflate;
use function gzuncompress;
use function substr;

/** @internal */
final class Flate
{
    public static function decode(string $data): string
    {
        $out = Silencer::run(static fn(): string|false => gzuncompress($data));
        if (false === $out) {
            // Some producers write raw deflate without the zlib header, or trailing garbage.
            $out = Silencer::run(static fn(): string|false => gzinflate($data));
        }
        if (false === $out) {
            $out = Silencer::run(static fn(): string|false => gzinflate(substr($data, offset: 2)));
        }
        if (false === $out) {
            throw new PdfException('Flate decompression failed.');
        }

        return $out;
    }

    public static function encode(string $data, int $level = 6): string
    {
        $out = gzcompress($data, $level);
        if (false === $out) {
            throw new PdfException('Flate compression failed.');
        }

        return $out;
    }
}
