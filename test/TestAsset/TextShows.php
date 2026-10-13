<?php

declare(strict_types=1);

namespace Php2PdfTest\TestAsset;

use function array_map;
use function is_numeric;
use function preg_match;
use function preg_match_all;
use function round;

/**
 * The text an uncompressed PDF shows, in order: each Tj or TJ operand with the position, in user
 * space before the page's own transformation, where its text line starts. The operators that
 * move the line are followed: Td, T* with TL, and Tm. A text object is taken to begin a line of
 * the content stream with BT and end with ET, as the library writes them, and its strings to
 * have their parentheses escaped.
 */
final class TextShows
{
    private const string OBJECT = '/(?<=\n)BT\b.*?\bET(?=\n)/s';

    private const string OPERATOR = '/^[A-Za-z*]+$/';

    private const string TOKEN =
        '/\((?:\\\\.|[^\\\\)])*\)|\[(?:\((?:\\\\.|[^\\\\)])*\)|[^\]\(])*\]|<[0-9A-Fa-f]*>'
            . '|\/[^\s\/\[\]()<>]+|[-+]?(?:\d+\.?\d*|\.\d+)|[A-Za-z*]+/s';

    /** @return list<array{0: float, 1: float, 2: string}> x, y and the operand shown */
    public static function of(string $pdf): array
    {
        $shows   = [];
        $leading = 0.0;
        foreach (self::objects($pdf) as $object) {
            $line     = [1.0, 0.0, 0.0, 1.0, 0.0, 0.0];
            $operands = [];
            $last     = '';
            foreach (self::tokens($object) as $token) {
                if (1 !== preg_match(self::OPERATOR, $token)) {
                    $operands[] = $token;
                    $last       = $token;
                    continue;
                }
                $n = array_map(self::number(...), $operands);
                match ($token) {
                    'Td'       => $line = self::moved($line, $n[0] ?? 0.0, $n[1] ?? 0.0),
                    'T*'       => $line = self::moved($line, 0.0, -$leading),
                    'TL'       => $leading = self::number($last),
                    'Tm' => $line = [
                        $n[0] ?? 1.0,
                        $n[1] ?? 0.0,
                        $n[2] ?? 0.0,
                        $n[3] ?? 1.0,
                        $n[4] ?? 0.0,
                        $n[5] ?? 0.0,
                    ],
                    'Tj', 'TJ' => $shows[] = [$line[4], $line[5], $last],
                    default    => null,
                };
                $operands = [];
                $last     = '';
            }
        }

        return $shows;
    }

    /**
     * The operators of each text object, in order, from BT to ET.
     *
     * @return list<list<string>>
     */
    public static function operators(string $pdf): array
    {
        $objects = [];
        foreach (self::objects($pdf) as $object) {
            $operators = [];
            foreach (self::tokens($object) as $token) {
                if (1 !== preg_match(self::OPERATOR, $token)) {
                    continue;
                }
                $operators[] = $token;
            }
            $objects[] = $operators;
        }

        return $objects;
    }

    /**
     * Where each shown run's line starts across, to six decimals.
     *
     * @return list<float>
     */
    public static function xs(string $pdf): array
    {
        $xs = [];
        foreach (self::of($pdf) as [$x]) {
            $xs[] = round($x, precision: 6);
        }

        return $xs;
    }

    /**
     * The line matrix moved by (tx, ty) in text space, as Td moves it.
     *
     * @param array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float} $line
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: float, 5: float}
     */
    private static function moved(array $line, float $tx, float $ty): array
    {
        [$a, $b, $c, $d, $e, $f] = $line;

        return [$a, $b, $c, $d, $e + ($tx * $a) + ($ty * $c), $f + ($tx * $b) + ($ty * $d)];
    }

    private static function number(string $operand): float
    {
        return is_numeric($operand) ? (float) $operand : 0.0;
    }

    /** @return list<string> */
    private static function objects(string $pdf): array
    {
        $objects = [];
        preg_match_all(self::OBJECT, $pdf, $objects);
        /** @var array{0?: list<string>} $objects */

        return $objects[0] ?? [];
    }

    /** @return list<string> */
    private static function tokens(string $object): array
    {
        $tokens = [];
        preg_match_all(self::TOKEN, $object, $tokens);
        /** @var array{0?: list<string>} $tokens */

        return $tokens[0] ?? [];
    }
}
