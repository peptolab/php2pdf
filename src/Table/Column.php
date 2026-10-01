<?php

declare(strict_types=1);

namespace Php2Pdf\Table;

use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Geometry\Unit;

/**
 * A table column: a fixed width in points, a percentage of the table width, or sized from its
 * content (auto), plus a style every cell in the column inherits.
 *
 * @api
 */
final readonly class Column
{
    /**
     * @param float|null $points  a fixed width
     * @param float|null $percent a share of the table width; auto when both are null
     */
    public function __construct(
        public ?float $points = null,
        public ?float $percent = null,
        public ?CellStyle $style = null,
    ) {
        if (null !== $points && null !== $percent) {
            throw new InvalidArgumentException('A column is fixed or a percentage, not both.');
        }
        if ((null !== $points && $points <= 0.0) || (null !== $percent && $percent <= 0.0)) {
            throw new InvalidArgumentException('Column widths must be positive.');
        }
    }

    /** Sized from its content: at least its widest word, at most its longest line. */
    public static function auto(?CellStyle $style = null): self
    {
        return new self(null, null, $style);
    }

    public static function mm(float $width, ?CellStyle $style = null): self
    {
        return new self(Unit::mm($width), null, $style);
    }

    public static function percent(float $share, ?CellStyle $style = null): self
    {
        return new self(null, $share, $style);
    }

    public static function pt(float $width, ?CellStyle $style = null): self
    {
        return new self($width, null, $style);
    }

    public function isAuto(): bool
    {
        return null === $this->points && null === $this->percent;
    }
}
