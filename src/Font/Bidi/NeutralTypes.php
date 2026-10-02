<?php

declare(strict_types=1);

namespace Php2Pdf\Font\Bidi;

use function array_slice;
use function count;
use function usort;

/**
 * Rules N0 to N2 of the bidi algorithm for an isolating run sequence: paired brackets, then runs
 * of neutrals, take a direction from the strong text inside and around them.
 *
 * @internal
 */
final class NeutralTypes
{
    /** Bracket pairs open at once, at most, before the search for pairs stops. */
    private const int MAX_BRACKETS = 63;

    /**
     * Rule N0: paired brackets take the embedding direction when it is strong inside them, or the
     * opposite direction when that is strong inside and before them; marks following a bracket
     * that changed take its direction.
     *
     * @param list<int> $types        the sequence's types after the weak rules
     * @param list<int> $original     its classes before them
     * @param list<int> $bracketTypes per position: 0 none, 1 opening, 2 closing
     * @param list<int> $bracketPairs per position: the closing bracket the pair is known by
     *
     * @return list<int>
     */
    public static function brackets(
        array $types,
        array $original,
        array $bracketTypes,
        array $bracketPairs,
        int $sos,
        int $level,
    ): array {
        $embedding = BidiClass::forLevel($level);
        foreach (self::pairs($types, $bracketTypes, $bracketPairs) as [$open, $close]) {
            $inside = self::strongInside($types, $open, $close, $embedding);
            if (null === $inside) {
                continue;
            }
            $direction =
                $inside === $embedding || self::strongBefore($types, $open, $sos) !== $inside ? $embedding : $inside;
            foreach ([$open, $close] as $bracket) {
                $types[$bracket] = $direction;
                for ($k = $bracket + 1; BidiClass::NSM === ($original[$k] ?? -1); ++$k) {
                    $types[$k] = $direction;
                }
            }
        }

        return $types;
    }

    /**
     * Rules N1 and N2: runs of neutrals take the direction of the strong text on both sides when
     * it agrees, else the embedding direction.
     *
     * @param list<int> $types
     *
     * @return list<int>
     */
    public static function neutrals(array $types, int $sos, int $eos, int $level): array
    {
        $count     = count($types);
        $embedding = BidiClass::forLevel($level);
        for ($i = 0; $i < $count;) {
            if (! BidiClass::isNeutralOrIsolate($types[$i] ?? -1)) {
                ++$i;
                continue;
            }
            $j = $i;
            while ($j < $count && BidiClass::isNeutralOrIsolate($types[$j] ?? -1)) {
                ++$j;
            }
            $before = 0 === $i ? $sos : BidiClass::strongForNeutrals($types[$i - 1] ?? BidiClass::ON) ?? $sos;
            $after  = $j >= $count ? $eos : BidiClass::strongForNeutrals($types[$j] ?? BidiClass::ON) ?? $eos;
            for ($k = $i; $k < $j; ++$k) {
                $types[$k] = $before === $after ? $before : $embedding;
            }
            $i = $j;
        }

        return $types;
    }

    /**
     * Rule BD16: the bracket pairs, as positions, by opening position.
     *
     * @param list<int> $types
     * @param list<int> $bracketTypes
     * @param list<int> $bracketPairs
     *
     * @return list<array{0: int, 1: int}>
     */
    private static function pairs(array $types, array $bracketTypes, array $bracketPairs): array
    {
        /** @var list<array{0: int, 1: int}> $stack */
        $stack = [];
        /** @var list<array{0: int, 1: int}> $pairs */
        $pairs = [];
        foreach ($bracketTypes as $k => $type) {
            if (0 === $type || BidiClass::ON !== ($types[$k] ?? -1)) {
                continue;
            }
            if (1 === $type) {
                if (count($stack) >= self::MAX_BRACKETS) {
                    break;
                }
                $stack[] = [$bracketPairs[$k] ?? -1, $k];
                continue;
            }
            for ($s = count($stack) - 1; $s >= 0; --$s) {
                $entry = $stack[$s] ?? [-2, 0];
                if ($entry[0] !== ($bracketPairs[$k] ?? -3)) {
                    continue;
                }
                $pairs[] = [$entry[1], $k];
                $stack   = array_slice($stack, offset: 0, length: $s);
                break;
            }
        }
        usort(
            $pairs,
            /**
             * @param array{0: int, 1: int} $a
             * @param array{0: int, 1: int} $b
             */
            static fn(array $a, array $b): int => $a[0] <=> $b[0],
        );

        return $pairs;
    }

    /** @param list<int> $types */
    private static function strongBefore(array $types, int $open, int $sos): int
    {
        for ($k = $open - 1; $k >= 0; --$k) {
            $strong = BidiClass::strongForNeutrals($types[$k] ?? BidiClass::ON);
            if (null !== $strong) {
                return $strong;
            }
        }

        return $sos;
    }

    /**
     * The embedding direction when it is strong between the brackets, else the other direction
     * when that is, else null.
     *
     * @param list<int> $types
     */
    private static function strongInside(array $types, int $open, int $close, int $embedding): ?int
    {
        $found = null;
        for ($k = $open + 1; $k < $close; ++$k) {
            $strong = BidiClass::strongForNeutrals($types[$k] ?? BidiClass::ON);
            if ($strong === $embedding) {
                return $embedding;
            }
            $found ??= $strong;
        }

        return $found;
    }
}
