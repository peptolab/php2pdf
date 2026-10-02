<?php

declare(strict_types=1);

namespace Php2Pdf\Core\Filter;

use Php2Pdf\Exception\ParseException;

use function ord;
use function pack;
use function preg_replace;
use function sprintf;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;

/** @internal */
final class Ascii85
{
    /** @throws ParseException */
    public static function decode(string $data): string
    {
        $data = preg_replace('/\s+/', replacement: '', subject: $data) ?? '';
        if (str_starts_with($data, '<~')) {
            $data = substr($data, offset: 2);
        }
        $end = strpos($data, needle: '~>');
        if (false !== $end) {
            $data = substr($data, offset: 0, length: $end);
        }

        $out    = '';
        $tuple  = 0;
        $count  = 0;
        $length = strlen($data);
        for ($i = 0; $i < $length; ++$i) {
            $c = ord($data[$i]);
            if (0x7A === $c && 0 === $count) {
                $out .= "\0\0\0\0";
                continue;
            }
            if ($c < 0x21 || $c > 0x75) {
                throw new ParseException(sprintf('Invalid ASCII85 character 0x%02X.', $c));
            }
            $tuple = ($tuple * 85) + ($c - 33);
            if (5 === ++$count) {
                $out   .= pack('N', $tuple & 0xFFFF_FFFF);
                $tuple = 0;
                $count = 0;
            }
        }
        if ($count > 0) {
            for ($i = $count; $i < 5; ++$i) {
                $tuple = ($tuple * 85) + 84;
            }
            $out .= substr(pack('N', $tuple & 0xFFFF_FFFF), offset: 0, length: $count - 1);
        }

        return $out;
    }
}
