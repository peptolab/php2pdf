<?php

declare(strict_types=1);

namespace Php2Pdf\Document;

/**
 * Standard page sizes. ISO sizes are defined in millimetres, US sizes in inches.
 *
 * @api
 */
enum PageSize
{
    case A0;
    case A1;
    case A2;
    case A3;
    case A4;
    case A5;
    case A6;
    case B4;
    case B5;
    case Letter;
    case Legal;
    case Tabloid;
    case Executive;

    public function dimensions(): Dimensions
    {
        return match ($this) {
            self::A0 => Dimensions::mm(841.0, 1189.0),
            self::A1 => Dimensions::mm(594.0, 841.0),
            self::A2 => Dimensions::mm(420.0, 594.0),
            self::A3 => Dimensions::mm(297.0, 420.0),
            self::A4 => Dimensions::mm(210.0, 297.0),
            self::A5 => Dimensions::mm(148.0, 210.0),
            self::A6 => Dimensions::mm(105.0, 148.0),
            self::B4 => Dimensions::mm(250.0, 353.0),
            self::B5 => Dimensions::mm(176.0, 250.0),
            self::Letter => Dimensions::in(8.5, 11.0),
            self::Legal => Dimensions::in(8.5, 14.0),
            self::Tabloid => Dimensions::in(11.0, 17.0),
            self::Executive => Dimensions::in(7.25, 10.5),
        };
    }

    public function landscape(): Dimensions
    {
        return $this->dimensions()->landscape();
    }

    public function portrait(): Dimensions
    {
        return $this->dimensions()->portrait();
    }
}
