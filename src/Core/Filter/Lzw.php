<?php

declare(strict_types=1);

namespace PhpPdf\Core\Filter;

use PhpPdf\Exception\PdfException;

use function chr;
use function count;
use function ord;
use function sprintf;
use function strlen;

use const PHP_INT_MAX;

/**
 * LZWDecode as PDF specifies it: 9 to 12-bit codes, MSB first, clear code 256, EOD 257, with
 * the EarlyChange parameter.
 *
 * @internal
 */
final class Lzw
{
    /** @throws PdfException for invalid codes or output growing past $maxBytes */
    public static function decode(string $data, int $earlyChange = 1, int $maxBytes = PHP_INT_MAX): string
    {
        $out        = '';
        $dictionary = [];
        $codeLength = 9;
        $previous   = null;
        $bitBuffer  = 0;
        $bitCount   = 0;
        $length     = strlen($data);
        $position   = 0;
        $next       = 258;

        while (true) {
            while ($bitCount < $codeLength && $position < $length) {
                $bitBuffer = (($bitBuffer << 8) | ord($data[$position])) & 0xFFFF_FFFF;
                $bitCount  += 8;
                ++$position;
            }
            if ($bitCount < $codeLength) {
                break;
            }
            $code     = ($bitBuffer >> ($bitCount - $codeLength)) & ((1 << $codeLength) - 1);
            $bitCount -= $codeLength;

            if (256 === $code) {
                $dictionary = [];
                $next       = 258;
                $codeLength = 9;
                $previous   = null;
                continue;
            }
            if (257 === $code) {
                break;
            }

            $entry = self::entry($code, $dictionary, $previous, $next);
            $out   .= $entry;
            if (strlen($out) > $maxBytes) {
                throw new PdfException(sprintf('An LZW stream decodes to more than %d bytes.', $maxBytes));
            }

            if (null !== $previous && count($dictionary) < 3838) {
                $dictionary[$next] = $previous . $entry[0];
                ++$next;
            }
            $previous   = $entry;
            $codeLength = self::codeLength($next + $earlyChange);
        }

        return $out;
    }

    private static function codeLength(int $limit): int
    {
        return match (true) {
            $limit >= 2048 => 12,
            $limit >= 1024 => 11,
            $limit >= 512 => 10,
            default => 9,
        };
    }

    /** @param array<int, string> $dictionary */
    private static function entry(int $code, array $dictionary, ?string $previous, int $next): string
    {
        if ($code < 256) {
            return chr($code);
        }
        $known = $dictionary[$code] ?? null;
        if (null !== $known) {
            return $known;
        }
        if (null !== $previous && $code === $next) {
            return $previous . $previous[0];
        }
        throw new PdfException(sprintf('Invalid LZW code %d.', $code));
    }
}
