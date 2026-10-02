<?php

declare(strict_types=1);

namespace Php2Pdf\Core\Filter;

use DeflateContext;
use InflateContext;
use Php2Pdf\Core\Silencer;
use Php2Pdf\Exception\ParseException;
use Php2Pdf\Exception\PdfException;

use function deflate_add;
use function deflate_init;
use function gzcompress;
use function inflate_add;
use function inflate_init;
use function sprintf;
use function strlen;
use function substr;

use const PHP_INT_MAX;
use const ZLIB_ENCODING_DEFLATE;
use const ZLIB_ENCODING_RAW;
use const ZLIB_FINISH;
use const ZLIB_NO_FLUSH;
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

    /**
     * Compresses the next piece of data given to a deflater. Fed in any pieces and then
     * finished, a deflater gives the bytes encode() gives for the whole.
     *
     * @throws PdfException
     */
    public static function deflate(DeflateContext $deflater, string $data): string
    {
        return self::add($deflater, $data, ZLIB_NO_FLUSH);
    }

    /**
     * A deflater for compressing data in pieces as encode() compresses it whole: the same level,
     * and zlib's largest memory level, as gzcompress() uses.
     *
     * @throws PdfException
     */
    public static function deflater(int $level = 6): DeflateContext
    {
        $deflater = deflate_init(ZLIB_ENCODING_DEFLATE, ['level' => $level, 'memory' => 9]);
        if (false === $deflater) {
            throw new PdfException('Flate compression failed.');
        }

        return $deflater;
    }

    public static function encode(string $data, int $level = 6): string
    {
        $out = gzcompress($data, $level);
        if (false === $out) {
            throw new PdfException('Flate compression failed.');
        }

        return $out;
    }

    /**
     * The last of a deflater's compressed data, ending its stream.
     *
     * @throws PdfException
     */
    public static function finish(DeflateContext $deflater): string
    {
        return self::add($deflater, '', ZLIB_FINISH);
    }

    /** @throws PdfException */
    private static function add(DeflateContext $deflater, string $data, int $flush): string
    {
        $out = deflate_add($deflater, $data, $flush);
        if (false === $out) {
            throw new PdfException('Flate compression failed.');
        }

        return $out;
    }

    /**
     * The inflated data, or null when it is not valid data of this encoding.
     *
     * @throws ParseException
     */
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
