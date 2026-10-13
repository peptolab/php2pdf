<?php

declare(strict_types=1);

namespace Php2Pdf\Import\Security;

use function min;
use function pack;
use function range;
use function strlen;
use function substr;
use function unpack;

/**
 * RC4, the cipher of PDF encryption revisions 2 and 3, in PHP: OpenSSL 3 no longer offers it.
 * Encrypting and decrypting are the same operation. The keystream is made a chunk at a time and
 * XORed with the data as strings, which PHP does in one go.
 *
 * @internal
 */
final class Rc4
{
    private const int CHUNK = 8192;

    public static function crypt(string $key, string $data): string
    {
        $s      = range(
            start: 0,
            end: 255,
        );
        $bytes  = unpack('C*', $key);
        $length = strlen($key);
        $j      = 0;
        for ($i = 0; $i < 256; ++$i) {
            $j     = ($j + ($s[$i] ?? 0) + (int) ($bytes[($i % $length) + 1] ?? 0)) & 0xFF;
            $t     = $s[$i] ?? 0;
            $s[$i] = $s[$j] ?? 0;
            $s[$j] = $t;
        }
        $out = '';
        $i   = 0;
        $j   = 0;
        for ($at = 0, $n = strlen($data); $at < $n; $at += self::CHUNK) {
            $size   = min(self::CHUNK, $n - $at);
            $stream = [];
            for ($k = 0; $k < $size; ++$k) {
                $i        = ($i + 1) & 0xFF;
                $si       = $s[$i] ?? 0;
                $j        = ($j + $si) & 0xFF;
                $sj       = $s[$j] ?? 0;
                $s[$i]    = $sj;
                $s[$j]    = $si;
                $stream[] = $s[($si + $sj) & 0xFF] ?? 0;
            }
            $out .= substr($data, $at, $size) ^ pack('C*', ...$stream);
        }

        return $out;
    }
}
