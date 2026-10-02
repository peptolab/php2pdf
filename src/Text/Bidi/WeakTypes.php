<?php

declare(strict_types=1);

namespace Php2Pdf\Text\Bidi;

use function count;

/**
 * Rules W1 to W7 of the bidi algorithm for an isolating run sequence: non-spacing marks, numbers,
 * and the separators and terminators around numbers.
 *
 * @internal
 */
final class WeakTypes
{
    /**
     * @param list<int> $types
     *
     * @return list<int>
     */
    public static function resolve(array $types, int $sos): array
    {
        $types = self::marks($types, $sos);
        $types = self::arabicNumbers($types, $sos);
        $types = self::separators($types);
        $types = self::terminators($types);

        return self::europeanNumbers($types, $sos);
    }

    /**
     * Rule W2 (European numbers after Arabic letters are Arabic numbers) and W3 (Arabic letters
     * are right-to-left).
     *
     * @param list<int> $types
     *
     * @return list<int>
     */
    private static function arabicNumbers(array $types, int $sos): array
    {
        $strong = $sos;
        foreach ($types as $k => $type) {
            if (BidiClass::EN === $type && BidiClass::AL === $strong) {
                $types[$k] = BidiClass::AN;
            }
            if (BidiClass::L === $type || BidiClass::R === $type || BidiClass::AL === $type) {
                $strong = $type;
            }
        }
        foreach ($types as $k => $type) {
            if (BidiClass::AL === $type) {
                $types[$k] = BidiClass::R;
            }
        }

        return $types;
    }

    /**
     * Rule W7: European numbers after left-to-right text are left-to-right.
     *
     * @param list<int> $types
     *
     * @return list<int>
     */
    private static function europeanNumbers(array $types, int $sos): array
    {
        $strong = $sos;
        foreach ($types as $k => $type) {
            if (BidiClass::EN === $type && BidiClass::L === $strong) {
                $types[$k] = BidiClass::L;
            }
            if (BidiClass::L === $type || BidiClass::R === $type) {
                $strong = $type;
            }
        }

        return $types;
    }

    /**
     * Rule W1: a non-spacing mark takes the class of the character before it, or neutral after an
     * isolate initiator or PDI.
     *
     * @param list<int> $types
     *
     * @return list<int>
     */
    private static function marks(array $types, int $sos): array
    {
        $previous = $sos;
        foreach ($types as $k => $type) {
            if (BidiClass::NSM === $type) {
                $types[$k] = $previous;
                continue;
            }
            $previous = BidiClass::isIsolateInitiator($type) || BidiClass::PDI === $type ? BidiClass::ON : $type;
        }

        return $types;
    }

    /**
     * Rule W4: a single separator between two numbers of a kind joins them.
     *
     * @param list<int> $types
     *
     * @return list<int>
     */
    private static function separators(array $types): array
    {
        $count = count($types);
        for ($k = 1; $k < ($count - 1); ++$k) {
            $type   = $types[$k] ?? BidiClass::ON;
            $before = $types[$k - 1] ?? BidiClass::ON;
            $after  = $types[$k + 1] ?? BidiClass::ON;
            if (BidiClass::ES === $type && BidiClass::EN === $before && BidiClass::EN === $after) {
                $types[$k] = BidiClass::EN;
            }
            if (
                BidiClass::CS === $type
                && $before === $after
                && (
                    BidiClass::EN === $before
                    || BidiClass::AN === $before
                )
            ) {
                $types[$k] = $before;
            }
        }

        return $types;
    }

    /**
     * Rules W5 (terminators next to European numbers join them) and W6 (other separators and
     * terminators are neutral).
     *
     * @param list<int> $types
     *
     * @return list<int>
     */
    private static function terminators(array $types): array
    {
        $count = count($types);
        for ($k = 0; $k < $count; ++$k) {
            if (BidiClass::ET !== ($types[$k] ?? -1)) {
                continue;
            }
            $end = $k;
            while ($end < $count && BidiClass::ET === ($types[$end] ?? -1)) {
                ++$end;
            }
            $touches = BidiClass::EN === ($types[$k - 1] ?? -1) || BidiClass::EN === ($types[$end] ?? -1);
            for ($m = $k; $m < $end; ++$m) {
                $types[$m] = $touches ? BidiClass::EN : BidiClass::ON;
            }
            $k = $end - 1;
        }
        foreach ($types as $k => $type) {
            if (BidiClass::ES === $type || BidiClass::CS === $type) {
                $types[$k] = BidiClass::ON;
            }
        }

        return $types;
    }
}
