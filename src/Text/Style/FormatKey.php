<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Style;

use Php2Pdf\Font\FontInterface;
use UnitEnum;

use function array_keys;
use function array_map;
use function get_object_vars;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_object;
use function is_string;
use function spl_object_id;
use function sprintf;
use function strlen;

/**
 * A string that two resolved formats share exactly when their values are equal, so equal formats
 * can be one object. Floats are written exactly; a font is told apart by the object, which the
 * format holds, so its identity cannot be reused while the key is in use.
 *
 * @internal
 */
final class FormatKey
{
    private function __construct() {}

    /** @param list<mixed> $values */
    public static function of(array $values): string
    {
        return self::value($values);
    }

    private static function value(mixed $value): string
    {
        return match (true) {
            null === $value => 'n',
            is_bool($value)   => $value ? 't' : 'f',
            is_int($value)    => "i{$value}",
            is_float($value)  => sprintf('d%.17g', $value),
            is_string($value) => 's' . strlen($value) . ":{$value}",
            is_array($value) => '['
                . implode(',', array_map(
                    static fn(int|string $key, mixed $item): string => self::value($key) . '=' . self::value($item),
                    array_keys($value),
                    $value,
                ))
                . ']',
            $value instanceof UnitEnum => 'e' . $value::class . "::{$value->name}",
            $value instanceof FontInterface => 'F' . spl_object_id($value),
            is_object($value) => 'o' . $value::class . self::value(get_object_vars($value)),
            default           => 'x',
        };
    }
}
