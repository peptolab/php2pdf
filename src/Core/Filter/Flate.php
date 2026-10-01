<?php

declare(strict_types=1);

namespace Php2Pdf\Core\Filter;

use InflateContext;
use Php2Pdf\Core\Silencer;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PdfException;

use function gzcompress;
use function inflate_add;
use function inflate_init;
use function sprintf;
use function strlen;
use function substr;

use const PHP_INT_MAX;
use const ZLIB_ENCODING_DEFLATE;
use const ZLIB_ENCODING_RAW;
use const ZLIB_SYNC_FLUSH;

/** @internal */
final class Flate
{
    /** Input is inflated in pieces this large, so the output can be checked against the limit as it grows. */
    private const int CHUNK = 8192;

    /**
     * Inflates zlib data, falling back to raw deflate for producers that leave out the zlib
     * header or add trailing garbage.
     *
     * @throws PdfException when the data cannot be inflated or would grow past $maxBytes
     */
    public static function decode(string $data, int $maxBytes = PHP_INT_MAX): string
    {
        foreach ([
            [ZLIB_ENCODING_DEFLATE, 0],
            [ZLIB_ENCODING_RAW,     0],
            [ZLIB_ENCODING_RAW,     2],
        ] as [$encoding, $skip]) {
            $out = self::inflate(substr($data, $skip), $encoding, $maxBytes);
            if (null !== $out) {
                return $out;
            }
        }
        throw new ParseException('Flate decompression failed.');
    }

    public static function encode(string $data, int $level = 6): string
    {
        $out = gzcompress($data, $level);
        if (false === $out) {
            throw new PdfException('Flate compression failed.');
        }

        return $out;
    }

    /** The inflated data, or null when it is not valid data of this encoding. */
    private static function inflate(string $data, int $encoding, int $maxBytes): ?string
    {
        $context = Silencer::run(static fn(): InflateContext|false => inflate_init($encoding));
        if (false === $context) {
            return null;
        }
        $out    = '';
        $length = strlen($data);
        for ($offset = 0; $offset < $length; $offset += self::CHUNK) {
            $piece = Silencer::run(static fn(): string|false => inflate_add(
                $context,
                substr($data, $offset, self::CHUNK),
                ZLIB_SYNC_FLUSH,
            ));
            if (false === $piece) {
                return '' === $out ? null : $out;
            }
            $out .= $piece;
            if (strlen($out) > $maxBytes) {
                throw new ParseException(sprintf('A compressed stream decodes to more than %d bytes.', $maxBytes));
            }
        }

        return '' === $out && '' !== $data ? null : $out;
    }
}
