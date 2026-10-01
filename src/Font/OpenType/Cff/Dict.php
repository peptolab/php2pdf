<?php

declare(strict_types=1);

namespace PhpPdf\Font\OpenType\Cff;

use PhpPdf\Exception\PdfException;
use PhpPdf\Font\OpenType\BinaryReader;

use function chr;
use function count;
use function implode;
use function is_numeric;
use function pack;
use function sprintf;
use function strlen;
use function substr;

/**
 * Reads and writes CFF DICT data and INDEX structures.
 *
 * @internal
 */
final class Dict
{
    /**
     * Encodes a DICT from entries. Operators of 1200 and above are escaped (12 x).
     *
     * @param array<int, string> $entries operator => encoded operands
     */
    public static function encode(array $entries): string
    {
        $data = '';
        foreach ($entries as $operator => $operands) {
            $data .= $operands . ($operator >= 1200 ? "\x0C" . chr($operator - 1200) : chr($operator));
        }

        return $data;
    }

    /**
     * An INDEX of the given items, with the smallest offset size that fits.
     *
     * @param list<string> $items
     */
    public static function index(array $items): string
    {
        $count = count($items);
        if (0 === $count) {
            return "\0\0";
        }
        $offsets = [1];
        $last    = 1;
        foreach ($items as $item) {
            $last      += strlen($item);
            $offsets[] = $last;
        }
        $size = match (true) {
            $last < 0x100 => 1,
            $last < 0x1_0000 => 2,
            $last < 0x100_0000 => 3,
            default => 4,
        };
        $index = pack('n', $count) . chr($size);
        foreach ($offsets as $offset) {
            $index .= substr(pack('N', $offset), 4 - $size);
        }

        return $index . implode('', $items);
    }

    /** An integer in the shortest DICT encoding. */
    public static function int(int $value): string
    {
        return match (true) {
            $value >= -107 && $value <= 107 => chr($value + 139),
            $value >= 108 && $value <= 1131 => chr((($value - 108) >> 8) + 247) . chr(($value - 108) & 0xFF),
            $value >= -1131 && $value <= -108 => chr(((-$value - 108) >> 8) + 251) . chr((-$value - 108) & 0xFF),
            $value >= -32_768 && $value <= 32_767 => "\x1C" . pack('n', $value & 0xFFFF),
            default => self::int32($value),
        };
    }

    /** An integer in the fixed five-byte encoding, so offsets can be filled in after sizing. */
    public static function int32(int $value): string
    {
        return "\x1D" . pack('N', $value & 0xFFFF_FFFF);
    }

    /**
     * Parses DICT data.
     *
     * @return array<int, DictEntry> operator (escaped operators as 1200 + x) => entry
     *
     * @throws PdfException when the data is malformed
     */
    public static function parse(string $data): array
    {
        $reader = new BinaryReader($data);
        $dict   = [];
        $start  = 0;
        $values = [];
        $i      = 0;
        while ($i < $reader->length) {
            $b0 = $reader->u8($i);
            if ($b0 <= 21) {
                $operator        = 12 === $b0 ? 1200 + $reader->u8($i + 1) : $b0;
                $i               += 12 === $b0 ? 2 : 1;
                $end             = $i - (12 === $b0 ? 2 : 1);
                $dict[$operator] = new DictEntry(substr($data, $start, $end - $start), $values);
                $start           = $i;
                $values          = [];
                continue;
            }
            [$values[], $length] = match (true) {
                28 === $b0 => [$reader->i16($i + 1), 3],
                29 === $b0 => [$reader->i32($i + 1), 5],
                30 === $b0 => self::readReal($reader, $i + 1),
                $b0 >= 32 && $b0 <= 246 => [$b0 - 139, 1],
                $b0 >= 247 && $b0 <= 250 => [(($b0 - 247) * 256) + $reader->u8($i + 1) + 108, 2],
                $b0 >= 251 && $b0 <= 254 => [(-($b0 - 251) * 256) - $reader->u8($i + 1) - 108, 2],
                default => throw new PdfException(sprintf('Invalid CFF DICT byte %d.', $b0)),
            };
            $i += $length;
        }

        return $dict;
    }

    /** A real-number nibble: 0-9, ".", "E", "E-" or "-". */
    private static function nibble(int $nibble): string
    {
        return match ($nibble) {
            0xA     => '.',
            0xB     => 'E',
            0xC     => 'E-',
            0xE     => '-',
            0xD     => throw new PdfException('Reserved nibble in a CFF real number.'),
            default => (string) $nibble,
        };
    }

    /**
     * A real number's value and encoded length, from the byte after the 30 prefix.
     *
     * @return array{0: float, 1: int}
     */
    private static function readReal(BinaryReader $reader, int $offset): array
    {
        $text = '';
        $i    = $offset;
        while (true) {
            $byte = $reader->u8($i++);
            foreach ([$byte >> 4, $byte & 0xF] as $nibble) {
                if (0xF !== $nibble) {
                    $text .= self::nibble($nibble);
                    continue;
                }
                if (! is_numeric($text)) {
                    throw new PdfException(sprintf('Invalid CFF real number "%s".', $text));
                }

                return [(float) $text, $i - $offset + 1];
            }
        }
    }
}
