<?php

declare(strict_types=1);

namespace PhpPdf\Geometry;

/**
 * Converts common units to points (1/72 inch). The library works in points internally.
 *
 * @api
 */
final class Unit
{
    public const float POINTS_PER_INCH = 72.0;
    public const float POINTS_PER_MM   = 72.0 / 25.4;
    public const float POINTS_PER_CM   = 720.0 / 25.4;

    public static function cm(float $value): float
    {
        return $value * self::POINTS_PER_CM;
    }

    public static function in(float $value): float
    {
        return $value * self::POINTS_PER_INCH;
    }

    public static function mm(float $value): float
    {
        return $value * self::POINTS_PER_MM;
    }

    public static function pt(float $value): float
    {
        return $value;
    }

    public static function toIn(float $points): float
    {
        return $points / self::POINTS_PER_INCH;
    }

    public static function toMm(float $points): float
    {
        return $points / self::POINTS_PER_MM;
    }
}
