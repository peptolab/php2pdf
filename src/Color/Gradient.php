<?php

declare(strict_types=1);

namespace PhpPdf\Color;

use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Name;
use PhpPdf\Exception\InvalidArgumentException;

/**
 * Shared behaviour of linear and radial gradients: validated stops in one colour space and the
 * PDF function that interpolates between them.
 *
 * @api
 */
abstract readonly class Gradient
{
    /** @var list<GradientStop> */
    public array $stops;

    /** @param list<GradientStop> $stops */
    public function __construct(array $stops, public bool $extend = true)
    {
        if (count($stops) < 2) {
            throw new InvalidArgumentException('A gradient needs at least two stops.');
        }
        usort($stops, static fn(GradientStop $a, GradientStop $b): int => $a->offset <=> $b->offset);
        $space = $stops[0]->color::class;
        foreach ($stops as $stop) {
            if ($stop->color::class !== $space) {
                throw new InvalidArgumentException('All gradient stops must use the same colour space.');
            }
        }
        $this->stops = array_values($stops);
    }

    /** A stable key for deduplicating the shading object within a document. */
    public function key(): string
    {
        return md5(static::class . serialize($this));
    }

    /** The shading dictionary (without the /ShadingType-specific coordinates). */
    abstract public function toShading(): Dictionary;

    public function colorSpaceName(): Name
    {
        return new Name(match ($this->stops[0]->color::class) {
            Gray::class => 'DeviceGray',
            Rgb::class => 'DeviceRGB',
            default => 'DeviceCMYK',
        });
    }

    protected function baseShading(int $type): Dictionary
    {
        return Dictionary::of([
            'ShadingType' => $type,
            'ColorSpace' => $this->colorSpaceName(),
            'Function' => $this->function(),
            'Extend' => [$this->extend, $this->extend],
        ]);
    }

    /** A Type 2 (exponential) function for two stops, or a Type 3 stitching function otherwise. */
    private function function(): Dictionary
    {
        $stops = $this->stops;
        $first = $stops[0];
        $last  = $stops[count($stops) - 1];
        $range = $last->offset - $first->offset;
        if ($range <= 0.0) {
            throw new InvalidArgumentException('Gradient stops must span a positive range.');
        }

        $functions = [];
        $bounds    = [];
        $encode    = [];
        for ($i = 1, $n = count($stops); $i < $n; ++$i) {
            $functions[] = Dictionary::of([
                'FunctionType' => 2,
                'Domain' => [0, 1],
                'C0' => $stops[$i - 1]->color->components(),
                'C1' => $stops[$i]->color->components(),
                'N' => 1,
            ]);
            if ($i < $n - 1) {
                $bounds[] = ($stops[$i]->offset - $first->offset) / $range;
            }
            array_push($encode, 0, 1);
        }
        if (1 === count($functions)) {
            return $functions[0];
        }

        return Dictionary::of([
            'FunctionType' => 3,
            'Domain' => [0, 1],
            'Functions' => $functions,
            'Bounds' => $bounds,
            'Encode' => $encode,
        ]);
    }
}
