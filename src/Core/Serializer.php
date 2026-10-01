<?php

declare(strict_types=1);

namespace PhpPdf\Core;

use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\HexString;
use PhpPdf\Core\Object\Name;
use PhpPdf\Core\Object\Raw;
use PhpPdf\Core\Object\Reference;
use PhpPdf\Core\Object\Stream;
use PhpPdf\Exception\PdfException;

/**
 * Serialises the object model to PDF syntax.
 *
 * Mapping: null/bool/int/float as themselves, PHP strings as literal strings, lists as arrays,
 * Dictionary, Name, Reference, HexString and Raw as their respective syntax. Streams are only
 * valid as indirect objects and are handled by the Writer.
 */
final class Serializer
{
    private const array STRING_ESCAPES = [
        '\\' => '\\\\',
        '(' => '\\(',
        ')' => '\\)',
        "\r" => '\\r',
        "\n" => '\\n',
        "\t" => '\\t',
        "\x08" => '\\b',
        "\x0c" => '\\f',
    ];

    public static function serialize(mixed $value): string
    {
        return match (true) {
            is_int($value), is_float($value) => Num::format($value),
            is_string($value) => self::literalString($value),
            $value instanceof Name => self::name($value->value),
            $value instanceof Reference => $value->id . ' ' . $value->generation . ' R',
            $value instanceof Dictionary => self::dictionary($value),
            is_array($value) => self::array($value),
            is_bool($value) => $value ? 'true' : 'false',
            $value === null => 'null',
            $value instanceof HexString => '<' . bin2hex($value->bytes) . '>',
            $value instanceof Raw => $value->syntax,
            $value instanceof Stream => throw new PdfException('Streams must be written as indirect objects.'),
            default => throw new PdfException('Cannot serialise value of type ' . get_debug_type($value)),
        };
    }

    public static function literalString(string $s): string
    {
        return '(' . strtr($s, self::STRING_ESCAPES) . ')';
    }

    public static function name(string $name): string
    {
        return '/' . preg_replace_callback(
            '/[^!-~]|[#()<>\[\]{}\/%]/',
            static fn(array $m): string => sprintf('#%02X', ord($m[0])),
            $name,
        );
    }

    public static function dictionary(Dictionary $dict): string
    {
        $out = '<<';
        foreach ($dict->all() as $key => $value) {
            $serialized = self::serialize($value);
            // A delimiter-initial value needs no separating space after the key name.
            $out .= self::name($key) . (str_contains('/[<(', $serialized[0]) ? '' : ' ') . $serialized;
        }

        return $out . '>>';
    }

    /** @param array<mixed> $items */
    public static function array(array $items): string
    {
        $parts = [];
        foreach ($items as $item) {
            $parts[] = self::serialize($item);
        }

        return '[' . implode(' ', $parts) . ']';
    }
}
