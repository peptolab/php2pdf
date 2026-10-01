<?php

declare(strict_types=1);

namespace Php2Pdf\Text;

use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Fit\FitMethod;
use Php2Pdf\Fit\Position;
use Php2Pdf\Font\TextOptions;

/**
 * How a single line of text is fitted into a box.
 *
 * With FitMethod::Auto the text is shrunk uniformly until it fits, but never below $shrinkLimit
 * of its size; Meet and Entire scale it like any other object, NoFit and Clip keep its size.
 *
 * @api
 */
final readonly class TextlineOptions
{
    public Position $position;
    public TextOptions $text;

    public function __construct(
        ?Position $position = null,
        public FitMethod $method = FitMethod::NoFit,
        public float $rotate = 0.0,
        ?TextOptions $text = null,
        public bool $underline = false,
        public bool $strikeout = false,
        public float $shrinkLimit = 0.75,
    ) {
        if ($shrinkLimit <= 0.0 || $shrinkLimit > 1.0) {
            throw new InvalidArgumentException('The shrink limit must be between 0 and 1.');
        }
        $this->position = $position ?? Position::bottomLeft();
        $this->text     = $text ?? TextOptions::default();
    }

    public static function at(Position $position): self
    {
        return new self($position);
    }

    public static function shrinkToFit(?Position $position = null, float $shrinkLimit = 0.75): self
    {
        return new self($position, FitMethod::Auto, shrinkLimit: $shrinkLimit);
    }
}
