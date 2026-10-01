<?php

declare(strict_types=1);

namespace Php2Pdf\Table;

use Php2Pdf\Color\ColorInterface;
use Php2Pdf\Text\FirstBaseline;
use Php2Pdf\Text\Style\Alignment;
use Php2Pdf\Text\Style\TextStyle;
use Php2Pdf\Text\Style\VerticalAlignment;

/**
 * How cells look: padding, background, borders, alignment, where the first baseline of text
 * sits and the text style for string content. Every property is optional; styles layer table < column < row < cell, later
 * non-null values winning. A side border beats the all-sides border.
 *
 * @api
 */
final readonly class CellStyle
{
    public function __construct(
        public ?Padding $padding = null,
        public ?ColorInterface $background = null,
        public ?Border $border = null,
        public ?Border $borderTop = null,
        public ?Border $borderRight = null,
        public ?Border $borderBottom = null,
        public ?Border $borderLeft = null,
        public ?VerticalAlignment $verticalAlignment = null,
        public ?Alignment $alignment = null,
        public TextStyle|string|null $text = null,
        public ?FirstBaseline $firstBaseline = null,
    ) {}

    /** This style with another layered on top. */
    public function merge(?self $over): self
    {
        if (null === $over) {
            return $this;
        }

        return new self(
            $over->padding ?? $this->padding,
            $over->background ?? $this->background,
            $over->border ?? $this->border,
            $over->borderTop ?? $this->borderTop,
            $over->borderRight ?? $this->borderRight,
            $over->borderBottom ?? $this->borderBottom,
            $over->borderLeft ?? $this->borderLeft,
            $over->verticalAlignment ?? $this->verticalAlignment,
            $over->alignment ?? $this->alignment,
            $over->text ?? $this->text,
            $over->firstBaseline ?? $this->firstBaseline,
        );
    }

    /** The effective border of a side: "top", "right", "bottom" or "left". */
    public function side(string $side): ?Border
    {
        return match ($side) {
            'top'    => $this->borderTop ?? $this->border,
            'right'  => $this->borderRight ?? $this->border,
            'bottom' => $this->borderBottom ?? $this->border,
            default  => $this->borderLeft ?? $this->border,
        };
    }
}
