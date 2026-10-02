<?php

declare(strict_types=1);

namespace Php2Pdf\Font\OpenType;

use Php2Pdf\Core\BinaryReader;
use Php2Pdf\Exception\ParseException;

use function max;

/**
 * Parses horizontal format 0 subtables of the legacy kern table.
 *
 * @internal
 */
final class KernTable
{
    /**
     * @return array<int, int> (left << 16 | right) => adjustment in font units
     *
     * @throws ParseException
     */
    public static function parse(BinaryReader $kern): array
    {
        $pairs = [];
        if (0 !== $kern->u16(0)) {
            // Apple-style version 1.0 table; rarely carries Latin kerning that GPOS lacks.
            return $pairs;
        }
        $subtables = $kern->u16(2);
        $offset    = 4;
        for ($i = 0; $i < $subtables && ($offset + 6) <= $kern->length; ++$i) {
            $length   = $kern->u16($offset + 2);
            $coverage = $kern->u16($offset + 4);
            $format   = $coverage >> 8;
            if (0 === $format && 0 !== ($coverage & 1)) {
                $count  = $kern->u16($offset + 6);
                $record = $offset + 14;
                for ($p = 0; $p < $count && ($record + 6) <= $kern->length; ++$p, $record += 6) {
                    $left                          = $kern->u16($record);
                    $right                         = $kern->u16($record + 2);
                    $pairs[($left << 16) | $right] = $kern->i16($record + 4);
                }
            }
            $offset += max(6, $length);
        }

        return $pairs;
    }
}
