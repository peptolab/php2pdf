<?php

declare(strict_types=1);

namespace Php2Pdf\Color;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Exception\InvalidArgumentException;

use function array_push;
use function array_values;
use function count;
use function floor;
use function max;
use function md5;
use function min;
use function serialize;
use function usort;

/**
 * Shared behaviour of linear and radial gradients: validated stops in one colour space and the
 * PDF function that interpolates between them.
 *
 * @api
 */
abstract readonly class AbstractGradient
{
    /** @var list<GradientStop> */
    public array $stops;

    /**
     * @param list<GradientStop> $stops
     * @param GradientSpread     $spread what the gradient does beyond its end points
     * @param float              $from   with repeat or reflect, the start of the stretch of the
     *                                   gradient vector to cover, in lengths of the vector
     *                                   (negative is before its start)
     * @param float              $to     the end of that stretch (above 1 is beyond its end)
     */
    public function __construct(
        array $stops,
        public bool $extend = true,
        public GradientSpread $spread = GradientSpread::Pad,
        public float $from = 0.0,
        public float $to = 1.0,
    ) {
        if (count($stops) < 2) {
            throw new InvalidArgumentException('A gradient needs at least two stops.');
        }
        usort($stops, static fn(GradientStop $a, GradientStop $b): int => $a->offset <=> $b->offset);
        $space = null;
        foreach ($stops as $stop) {
            $space ??= $stop->color::class;
            if ($stop->color::class !== $space) {
                throw new InvalidArgumentException('All gradient stops must use the same colour space.');
            }
        }
        $this->stops = array_values($stops);
    }

    /** The shading dictionary (without the /ShadingType-specific coordinates). */
    abstract public function toShading(): Dictionary;

    public function colorSpaceName(): Name
    {
        $first = $this->stops[0] ?? throw new InvalidArgumentException('A gradient needs at least two stops.');

        return new Name(match ($first->color::class) {
            Gray::class => 'DeviceGray',
            Rgb::class  => 'DeviceRGB',
            default     => 'DeviceCMYK',
        });
    }

    /** A stable key for deduplicating the shading object within a document. */
    public function key(): string
    {
        return md5(static::class . serialize($this));
    }

    protected function baseShading(int $type): Dictionary
    {
        if (GradientSpread::Pad === $this->spread) {
            return Dictionary::of([
                'ShadingType' => $type,
                'ColorSpace'  => $this->colorSpaceName(),
                'Function'    => $this->function(),
                'Extend'      => [$this->extend, $this->extend],
            ]);
        }
        [$from, $to] = $this->domain();

        return Dictionary::of([
            'ShadingType' => $type,
            'ColorSpace'  => $this->colorSpaceName(),
            'Domain'      => [$from, $to],
            'Function'    => $this->spreadFunction($from, $to),
            'Extend'      => [true, true],
        ]);
    }

    /**
     * The stretch of the gradient vector the shading covers, in lengths of the vector: 0 to 1
     * when padded, the requested stretch when repeated or reflected.
     *
     * @return array{0: float, 1: float}
     */
    protected function domain(): array
    {
        if (GradientSpread::Pad === $this->spread) {
            return [0.0, 1.0];
        }

        return [min(0.0, $this->from), max(1.0, $this->to)];
    }

    /** A Type 2 (exponential) function for two stops, or a Type 3 stitching function otherwise. */
    private function function(): Dictionary
    {
        $first = $this->stops[0] ?? throw new InvalidArgumentException('A gradient needs at least two stops.');
        $last  = $this->stops[count($this->stops) - 1] ?? $first;
        $range = $last->offset - $first->offset;
        if ($range <= 0.0) {
            throw new InvalidArgumentException('Gradient stops must span a positive range.');
        }

        $functions = [];
        $bounds    = [];
        $encode    = [];
        $previous  = null;
        foreach ($this->stops as $stop) {
            if (null !== $previous) {
                $functions[] = Dictionary::of([
                    'FunctionType' => 2,
                    'Domain'       => [0, 1],
                    'C0'           => $previous->color->components(),
                    'C1'           => $stop->color->components(),
                    'N'            => 1,
                ]);
                if ($stop !== $last) {
                    $bounds[] = ($stop->offset - $first->offset) / $range;
                }
                array_push($encode, 0, 1);
            }
            $previous = $stop;
        }
        if (1 === count($functions)) {
            return $functions[0];
        }

        return Dictionary::of([
            'FunctionType' => 3,
            'Domain'       => [0, 1],
            'Functions'    => $functions,
            'Bounds'       => $bounds,
            'Encode'       => $encode,
        ]);
    }

    /**
     * The gradient's colours repeated over $from to $to: one copy per length of the vector,
     * every other one running backwards when reflected.
     */
    private function spreadFunction(float $from, float $to): Dictionary
    {
        $base      = $this->function();
        $functions = [];
        $bounds    = [];
        $encode    = [];
        for ($period = (int) floor($from); $period < $to; ++$period) {
            $start = max($from, (float) $period) - $period;
            $end   = min($to, (float) ($period + 1)) - $period;
            $back  = GradientSpread::Reflect === $this->spread && 0 !== ($period & 1);
            array_push($encode, $back ? 1.0 - $start : $start, $back ? 1.0 - $end : $end);
            $functions[] = $base;
            if (($period + 1) < $to) {
                $bounds[] = (float) ($period + 1);
            }
        }

        return Dictionary::of([
            'FunctionType' => 3,
            'Domain'       => [$from, $to],
            'Functions'    => $functions,
            'Bounds'       => $bounds,
            'Encode'       => $encode,
        ]);
    }
}
