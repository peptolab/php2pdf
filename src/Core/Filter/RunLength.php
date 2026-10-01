<?php

declare(strict_types=1);

namespace Php2Pdf\Core\Filter;

use function ord;
use function str_repeat;
use function strlen;
use function substr;

/** @internal */
final class RunLength
{
    public static function decode(string $data): string
    {
        $out    = '';
        $length = strlen($data);
        $i      = 0;
        while ($i < $length) {
            $n = ord($data[$i++]);
            if (128 === $n) {
                break;
            }
            if ($n < 128) {
                $out .= substr($data, $i, $n + 1);
                $i   += $n + 1;
                continue;
            }
            if ($i < $length) {
                $out .= str_repeat($data[$i], 257 - $n);
            }
            ++$i;
        }

        return $out;
    }
}
