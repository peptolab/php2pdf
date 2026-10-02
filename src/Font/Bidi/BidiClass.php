<?php

declare(strict_types=1);

namespace Php2Pdf\Font\Bidi;

use function in_array;

/**
 * Bidi classes, numbered as ICU numbers them (IntlChar::charDirection()), and the groupings the
 * algorithm's rules use.
 *
 * @internal
 */
final class BidiClass
{
    public const int L   = 0;
    public const int R   = 1;
    public const int EN  = 2;
    public const int ES  = 3;
    public const int ET  = 4;
    public const int AN  = 5;
    public const int CS  = 6;
    public const int B   = 7;
    public const int S   = 8;
    public const int WS  = 9;
    public const int ON  = 10;
    public const int LRE = 11;
    public const int LRO = 12;
    public const int AL  = 13;
    public const int RLE = 14;
    public const int RLO = 15;
    public const int PDF = 16;
    public const int NSM = 17;
    public const int BN  = 18;
    public const int FSI = 19;
    public const int LRI = 20;
    public const int RLI = 21;
    public const int PDI = 22;

    public static function forLevel(int $level): int
    {
        return 0 === ($level & 1) ? self::L : self::R;
    }

    public static function isIsolateInitiator(int $class): bool
    {
        return self::FSI === $class || self::LRI === $class || self::RLI === $class;
    }

    public static function isNeutralOrIsolate(int $class): bool
    {
        return in_array(
            $class,
            [self::B, self::S, self::WS, self::ON, self::FSI, self::LRI, self::RLI, self::PDI],
            strict: true,
        );
    }

    /** Whether rule X9 removes the class: embedding and override controls, and boundary neutrals. */
    public static function isRemoved(int $class): bool
    {
        return in_array($class, [self::LRE, self::RLE, self::LRO, self::RLO, self::PDF, self::BN], strict: true);
    }

    /** The strong direction a type counts as for neutrals: numbers count as right-to-left. */
    public static function strongForNeutrals(int $type): ?int
    {
        return match ($type) {
            self::L => self::L,
            self::R, self::EN, self::AN => self::R,
            default => null,
        };
    }
}
