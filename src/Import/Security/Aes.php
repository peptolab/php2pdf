<?php

declare(strict_types=1);

namespace Php2Pdf\Import\Security;

use Php2Pdf\Exception\ParseException;

use function extension_loaded;
use function intdiv;
use function openssl_decrypt;
use function openssl_encrypt;
use function ord;
use function str_repeat;
use function strlen;
use function substr;

use const OPENSSL_RAW_DATA;
use const OPENSSL_ZERO_PADDING;

/**
 * AES in CBC mode, through the openssl extension, for PDF encryption revisions 4 to 6. Strings
 * and streams carry their initialisation vector in their first 16 bytes and are padded to whole
 * blocks; padding a writer got wrong is kept rather than refused.
 *
 * @internal
 */
final class Aes
{
    /**
     * Data encrypted with an IV before it, decrypted and unpadded.
     *
     * @throws ParseException without the openssl extension
     */
    public static function decrypt(string $key, string $data): string
    {
        $blocks = substr($data, offset: 16, length: (intdiv(strlen($data), num2: 16) - 1) * 16);
        if ('' === $blocks) {
            return '';
        }
        $plain = self::raw('decrypt', $key, substr($data, offset: 0, length: 16), $blocks);
        $pad   = ord($plain[strlen($plain) - 1]);

        return $pad >= 1 && $pad <= 16 && substr($plain, -$pad) === str_repeat($plain[strlen($plain) - 1], $pad)
            ? substr($plain, offset: 0, length: -$pad)
            : $plain;
    }

    /**
     * Whole blocks decrypted with no padding, as revision 6 unwraps the file key.
     *
     * @throws ParseException without the openssl extension
     */
    public static function decryptBlocks(string $key, string $iv, string $data): string
    {
        return self::raw('decrypt', $key, $iv, $data);
    }

    /**
     * Whole blocks encrypted with no padding, as revision 6 hashes a password.
     *
     * @throws ParseException without the openssl extension
     */
    public static function encryptBlocks(string $key, string $iv, string $data): string
    {
        return self::raw('encrypt', $key, $iv, $data);
    }

    /** @throws ParseException */
    private static function raw(string $direction, string $key, string $iv, string $data): string
    {
        if (! extension_loaded('openssl')) {
            throw new ParseException('This PDF is encrypted with AES, which needs the openssl extension.');
        }
        $cipher = 32 === strlen($key) ? 'aes-256-cbc' : 'aes-128-cbc';
        $flags  = OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING;
        $out    = 'decrypt' === $direction
            ? openssl_decrypt($data, $cipher, $key, $flags, $iv)
            : openssl_encrypt($data, $cipher, $key, $flags, $iv);

        return false === $out ? '' : $out;
    }
}
