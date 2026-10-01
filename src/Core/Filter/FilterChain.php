<?php

declare(strict_types=1);

namespace PhpPdf\Core\Filter;

use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Name;
use PhpPdf\Exception\ParseException;

use function array_key_exists;
use function array_pad;
use function end;
use function is_array;
use function is_float;
use function is_int;
use function sprintf;

use const PHP_INT_MAX;

/**
 * Applies a stream's /Filter chain with its /DecodeParms. Image codecs (DCT, JPX, CCITT, JBIG2)
 * are not decoded; decode() reports them so callers can pass such streams through untouched.
 *
 * @internal
 */
final class FilterChain
{
    private const array IMAGE_CODECS = [
        'DCTDecode'      => true,
        'JPXDecode'      => true,
        'CCITTFaxDecode' => true,
        'JBIG2Decode'    => true,
    ];

    /**
     * @param list<string>          $filters
     * @param list<Dictionary|null> $parms    one entry per filter
     * @param int                   $maxBytes the most any stage may decode to
     */
    public static function decode(string $data, array $filters, array $parms, int $maxBytes = PHP_INT_MAX): string
    {
        foreach ($filters as $i => $filter) {
            $p    = $parms[$i] ?? null;
            $data = match ($filter) {
                'FlateDecode', 'Fl'     => self::predict(Flate::decode($data, $maxBytes), $p),
                'LZWDecode', 'LZW' => self::predict(Lzw::decode($data, self::int($p, 'EarlyChange', 1), $maxBytes), $p),
                'ASCII85Decode', 'A85'  => Ascii85::decode($data),
                'ASCIIHexDecode', 'AHx' => AsciiHex::decode($data),
                'RunLengthDecode', 'RL' => RunLength::decode($data),
                'Crypt'                 => $data,
                default                 => throw new ParseException(sprintf(
                    'Unsupported stream filter "%s".',
                    $filter,
                )),
            };
        }

        return $data;
    }

    /** @param list<string> $filters */
    public static function endsInImageCodec(array $filters): bool
    {
        $last = end($filters);

        return false !== $last && array_key_exists($last, self::IMAGE_CODECS);
    }

    /**
     * The filter names of a stream dictionary as a list.
     *
     * @return list<string>
     */
    public static function filters(mixed $filter): array
    {
        if ($filter instanceof Name) {
            return [$filter->value];
        }
        $names = [];
        if (! is_array($filter)) {
            return $names;
        }
        foreach ($filter as $item) {
            if (! $item instanceof Name) {
                continue;
            }
            $names[] = $item->value;
        }

        return $names;
    }

    /**
     * The decode parameters as a list parallel to filters().
     *
     * @return list<Dictionary|null>
     */
    public static function parms(mixed $parms, int $count): array
    {
        $list = [];
        if ($parms instanceof Dictionary) {
            $list[] = $parms;
        }
        if (is_array($parms)) {
            foreach ($parms as $item) {
                $list[] = $item instanceof Dictionary ? $item : null;
            }
        }

        return array_pad($list, $count, value: null);
    }

    private static function int(?Dictionary $parms, string $key, int $default): int
    {
        $value = $parms?->get($key);

        return is_int($value) || is_float($value) ? (int) $value : $default;
    }

    private static function predict(string $data, ?Dictionary $parms): string
    {
        $predictor = self::int($parms, 'Predictor', 1);
        if ($predictor <= 1) {
            return $data;
        }

        return Predictor::decode(
            $data,
            $predictor,
            self::int($parms, 'Colors', 1),
            self::int($parms, 'BitsPerComponent', 8),
            self::int($parms, 'Columns', 1),
        );
    }
}
