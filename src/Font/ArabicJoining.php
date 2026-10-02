<?php

declare(strict_types=1);

namespace Php2Pdf\Font;

use IntlChar;

use function class_exists;

/**
 * The joining form of each character of Arabic and the other joining scripts, as HarfBuzz's
 * Arabic shaper decides it from Unicode joining types and groups: isolated, initial, medial or
 * final, and Syriac's second and third finals and second medial for alaph. Transparent
 * characters (marks) take no form and do not break joining.
 *
 * @internal
 */
final class ArabicJoining
{
    /** The forms, as the OpenType features that select them. */
    public const array FEATURES = ['isol', 'fina', 'fin2', 'fin3', 'medi', 'med2', 'init'];

    private const int U      = 0;
    private const int L      = 1;
    private const int R      = 2;
    private const int D      = 3;
    private const int ALAPH  = 4;
    private const int DALATH = 5;
    private const int T      = 6;

    /**
     * HarfBuzz's state table: per state and joining type, the form for the previous character
     * (null to keep it), the form for this one, and the next state.
     *
     * @var list<list<array{0: ?string, 1: ?string, 2: int}>>
     */
    private const array STATES = [
        [
            [null, null, 0],
            [null, 'isol', 2],
            [null, 'isol', 1],
            [null, 'isol', 2],
            [null, 'isol', 1],
            [null, 'isol', 6],
        ],
        [
            [null, null, 0],
            [null, 'isol', 2],
            [null, 'isol', 1],
            [null, 'isol', 2],
            [null, 'fin2', 5],
            [null, 'isol', 6],
        ],
        [
            [null, null, 0],
            [null, 'isol', 2],
            ['init', 'fina', 1],
            ['init', 'fina', 3],
            ['init', 'fina', 4],
            ['init', 'fina', 6],
        ],
        [
            [null, null, 0],
            [null, 'isol', 2],
            ['medi', 'fina', 1],
            ['medi', 'fina', 3],
            ['medi', 'fina', 4],
            ['medi', 'fina', 6],
        ],
        [
            [null, null, 0],
            [null, 'isol', 2],
            ['med2', 'isol', 1],
            ['med2', 'isol', 2],
            ['med2', 'fin2', 5],
            ['med2', 'isol', 6],
        ],
        [
            [null, null, 0],
            [null, 'isol', 2],
            ['isol', 'isol', 1],
            ['isol', 'isol', 2],
            ['isol', 'fin2', 5],
            ['isol', 'isol', 6],
        ],
        [
            [null, null, 0],
            [null, 'isol', 2],
            [null, 'isol', 1],
            [null, 'isol', 2],
            [null, 'fin3', 5],
            [null, 'isol', 6],
        ],
    ];

    /**
     * The form of each character ('' for none), in logical order.
     *
     * @param list<int> $codePoints
     *
     * @return list<string>
     */
    public static function forms(array $codePoints): array
    {
        $forms = [];
        foreach ($codePoints as $_) {
            $forms[] = '';
        }
        if (! class_exists(IntlChar::class)) {
            return $forms;
        }
        $state    = 0;
        $previous = null;
        foreach ($codePoints as $i => $codePoint) {
            $type = self::type($codePoint);
            if (self::T === $type) {
                continue;
            }
            [$before, $form, $state] = self::STATES[$state][$type] ?? [null, null, 0];
            if (null !== $before && null !== $previous) {
                $forms[$previous] = $before;
            }
            $forms[$i] = $form ?? '';
            $previous  = $i;
        }

        return $forms;
    }

    /**
     * The joining type column of a character, with Syriac's alaph and dalath-rish groups.
     *
     * @return int<0, 6>
     */
    private static function type(int $codePoint): int
    {
        $group = (int) IntlChar::getIntPropertyValue($codePoint, IntlChar::PROPERTY_JOINING_GROUP);
        if (IntlChar::JG_ALAPH === $group) {
            return self::ALAPH;
        }
        if (IntlChar::JG_DALATH_RISH === $group) {
            return self::DALATH;
        }

        return match ((int) IntlChar::getIntPropertyValue($codePoint, IntlChar::PROPERTY_JOINING_TYPE)) {
            IntlChar::JT_JOIN_CAUSING, IntlChar::JT_DUAL_JOINING => self::D,
            IntlChar::JT_LEFT_JOINING                            => self::L,
            IntlChar::JT_RIGHT_JOINING                           => self::R,
            IntlChar::JT_TRANSPARENT                             => self::T,
            default                                              => self::U,
        };
    }
}
