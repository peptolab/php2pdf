<?php

declare(strict_types=1);

namespace Php2Pdf\Core;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\HexString;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Raw;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Exception\InvalidArgumentException;

use function bin2hex;
use function count;
use function get_debug_type;
use function implode;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function ord;
use function preg_replace_callback;
use function sprintf;
use function str_contains;
use function strlen;
use function strtr;

/**
 * Serialises the object model to PDF syntax.
 *
 * Mapping: null/bool/int/float as themselves, PHP strings as literal strings, lists as arrays,
 * Dictionary, Name, Reference, HexString and Raw as their respective syntax. Streams are only
 * valid as indirect objects and are handled by the Writer.
 *
 * @internal
 */
final class Serializer
{
    private const array STRING_ESCAPES = [
        '\\'   => '\\\\',
        '('    => '\\(',
        ')'    => '\\)',
        "\r"   => '\\r',
        "\n"   => '\\n',
        "\t"   => '\\t',
        "\x08" => '\\b',
        "\x0c" => '\\f',
    ];

    /** Escaped names are kept up to this many; the same few dozen keys and values recur in every document. */
    private const int NAME_CACHE_LIMIT = 4096;

    /** Longer names (from imported files, say) are not cached, so the cache stays small across documents. */
    private const int NAME_CACHE_MAX_LENGTH = 64;

    /** @var array<string, string> serialised names (with the slash) by unescaped value */
    private static array $names = [];

    /** @param array<mixed> $items */
    public static function array(array $items): string
    {
        $parts = [];
        foreach ($items as $item) {
            $parts[] = self::serialize($item);
        }

        return '[' . implode(' ', $parts) . ']';
    }

    public static function dictionary(Dictionary $dict): string
    {
        $out = '<<';
        foreach ($dict->all() as $key => $value) {
            // The commonest values first: names and references need no generic dispatch.
            if ($value instanceof Name) {
                $out .= (self::$names[$key] ?? self::name((string) $key)) . (self::$names[$value->value] ?? self::name($value->value));
                continue;
            }
            if ($value instanceof Reference) {
                $out .= (self::$names[$key] ?? self::name((string) $key)) . " {$value->id} {$value->generation} R";
                continue;
            }
            if (is_int($value)) {
                $out .= (self::$names[$key] ?? self::name((string) $key)) . " {$value}";
                continue;
            }
            $serialized = self::serialize($value);
            // A delimiter-initial value needs no separating space after the key name.
            $out .= (self::$names[$key] ?? self::name((string) $key)) . (str_contains('/[<(', $serialized[0]) ? '' : ' ') . $serialized;
        }

        return "{$out}>>";
    }

    public static function literalString(string $s): string
    {
        return '(' . strtr($s, self::STRING_ESCAPES) . ')';
    }

    public static function name(string $name): string
    {
        if (isset(self::$names[$name])) {
            return self::$names[$name];
        }
        // A fixed byte-wise pattern with no backtracking cannot fail.
        /** @var string $escaped */
        $escaped = preg_replace_callback(
            '/[^!-~]|[#()<>\[\]{}\/%]/',
            /** @param array<array-key, string> $m */
            static fn(array $m): string => sprintf('#%02X', ord($m[0] ?? '')),
            $name,
        );
        if (strlen($name) > self::NAME_CACHE_MAX_LENGTH) {
            return "/{$escaped}";
        }
        if (count(self::$names) >= self::NAME_CACHE_LIMIT) {
            self::$names = [];
        }

        return self::$names[$name] = "/{$escaped}";
    }

    public static function serialize(mixed $value): string
    {
        return match (true) {
            is_int($value) => (string) $value,
            $value instanceof Name => self::$names[$value->value] ?? self::name($value->value),
            $value instanceof Reference => "{$value->id} {$value->generation} R",
            is_float($value) => Num::format($value),
            $value instanceof Dictionary => self::dictionary($value),
            is_array($value) => self::array($value),
            is_string($value) => self::literalString($value),
            is_bool($value)  => $value ? 'true' : 'false',
            null === $value => 'null',
            $value instanceof HexString => '<' . bin2hex($value->bytes) . '>',
            $value instanceof Raw => $value->syntax,
            $value instanceof Stream => throw new InvalidArgumentException(
                'Streams must be written as indirect objects.',
            ),
            default => throw new InvalidArgumentException('Cannot serialise value of type ' . get_debug_type($value)),
        };
    }
}
