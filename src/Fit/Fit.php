<?php

declare(strict_types=1);

namespace Php2Pdf\Fit;

use Php2Pdf\Exception\InvalidArgumentException;

/**
 * Options for placing an object in a box: fit method, position, rotation and natural-size
 * overrides. Immutable; the named constructors cover the common cases.
 *
 * @api
 */
final readonly class Fit
{
    public Position $position;

    /**
     * @param float      $scaleX rotation-independent scale applied to the natural size (NoFit, Clip, Auto)
     * @param float|null $scaleY defaults to $scaleX
     * @param float|null $dpi    overrides an image's own resolution when computing its natural size
     * @param float      $rotate degrees; counter-clockwise on bottom-left pages, clockwise on top-down pages
     */
    public function __construct(
        public FitMethod $method = FitMethod::Meet,
        ?Position $position = null,
        public float $rotate = 0.0,
        public float $scaleX = 1.0,
        public ?float $scaleY = null,
        public ?float $dpi = null,
    ) {
        if ($scaleX <= 0.0 || (null !== $scaleY && $scaleY <= 0.0)) {
            throw new InvalidArgumentException('Scale factors must be positive.');
        }
        if (null !== $dpi && $dpi <= 0.0) {
            throw new InvalidArgumentException('Resolution must be positive.');
        }
        $this->position = $position ?? Position::center();
    }

    public static function auto(?Position $position = null, float $rotate = 0.0): self
    {
        return new self(FitMethod::Auto, $position, $rotate);
    }

    public static function clip(?Position $position = null, float $rotate = 0.0, float $scale = 1.0): self
    {
        return new self(FitMethod::Clip, $position, $rotate, $scale);
    }

    public static function entire(float $rotate = 0.0): self
    {
        return new self(FitMethod::Entire, null, $rotate);
    }

    public static function meet(?Position $position = null, float $rotate = 0.0): self
    {
        return new self(FitMethod::Meet, $position, $rotate);
    }

    public static function noFit(?Position $position = null, float $rotate = 0.0, float $scale = 1.0): self
    {
        return new self(FitMethod::NoFit, $position, $rotate, $scale);
    }

    public static function slice(?Position $position = null, float $rotate = 0.0): self
    {
        return new self(FitMethod::Slice, $position, $rotate);
    }

    public function withDpi(?float $dpi): self
    {
        return new self($this->method, $this->position, $this->rotate, $this->scaleX, $this->scaleY, $dpi);
    }

    public function withPosition(Position $position): self
    {
        return new self($this->method, $position, $this->rotate, $this->scaleX, $this->scaleY, $this->dpi);
    }

    public function withRotation(float $degrees): self
    {
        return new self($this->method, $this->position, $degrees, $this->scaleX, $this->scaleY, $this->dpi);
    }
}
