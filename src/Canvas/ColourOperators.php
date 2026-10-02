<?php

declare(strict_types=1);

namespace Php2Pdf\Canvas;

use Php2Pdf\Color\AbstractGradient;
use Php2Pdf\Color\Cmyk;
use Php2Pdf\Color\ColorInterface;
use Php2Pdf\Color\ColourPolicyInterface;
use Php2Pdf\Color\DeviceN;
use Php2Pdf\Color\Gray;
use Php2Pdf\Color\IccColor;
use Php2Pdf\Color\Lab;
use Php2Pdf\Color\Rgb;
use Php2Pdf\Color\Spot;
use Php2Pdf\Core\Num;
use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;
use Php2Pdf\Core\Object\Stream;
use Php2Pdf\Core\Objects;
use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Geometry\Matrix;
use Php2Pdf\Template\Template;

use function array_fill;
use function array_map;
use function array_merge;
use function count;
use function in_array;
use function sprintf;
use function str_repeat;

/**
 * The operators that select a colour, and the colour spaces and shadings they need, written once
 * per document and named in the canvas's resources. PDF/X rules are checked as colours are used.
 *
 * @internal
 */
final readonly class ColourOperators
{
    public function __construct(
        private Objects $objects,
        private ColourPolicyInterface $colour,
        private Resources $resources,
    ) {}

    /** The device colour space a colour is drawn in; Lab, or ICCBased when it carries its own profile. */
    private static function deviceSpace(ColorInterface $color): string
    {
        return match (true) {
            $color instanceof Gray => 'DeviceGray',
            $color instanceof Rgb => 'DeviceRGB',
            $color instanceof Cmyk => 'DeviceCMYK',
            $color instanceof Lab => 'Lab',
            default => 'ICCBased',
        };
    }

    /**
     * The tint transform of a DeviceN space, as a PostScript calculator function. Subtractive
     * alternates (CMYK) give 1 - prod(1 - t * c) per component, additive ones (Gray, RGB)
     * prod(1 - t * (1 - a)), so a single colorant matches its Separation space.
     */
    private static function mixFunction(DeviceN $color): Stream
    {
        $inputs      = count($color->colorants);
        $subtractive = $color->alternate() instanceof Cmyk;
        $outputs     = count($color->alternate()->components());
        $code        = '';
        for ($j = 0; $j < $outputs; ++$j) {
            $code .= '1';
            foreach ($color->colorants as $i => $colorant) {
                $value  = $colorant->alternate->components()[$j] ?? 0.0;
                $factor = $subtractive ? $value : 1.0 - $value;
                if (0.0 === $factor) {
                    continue;
                }
                $depth = $inputs - $i + $j;
                $code  .= " {$depth} index " . Num::format($factor, decimals: 6) . ' mul 1 exch sub mul';
            }
            $code .= $subtractive ? " 1 exch sub\n" : "\n";
        }
        $code .= ($inputs + $outputs) . " {$outputs} roll" . str_repeat(' pop', $inputs);

        return new Stream(Dictionary::of([
            'FunctionType' => 4,
            'Domain'       => array_merge(...array_fill(0, $inputs, [0, 1])),
            'Range'        => array_merge(...array_fill(0, $outputs, [0, 1])),
        ]), "{\n{$code}\n}");
    }

    /** @param array{string, string, string, string, string} $ops gray, rgb, cmyk, colour-space and component operators */
    public function ops(ColorInterface $color, array $ops): string
    {
        $components = Num::list($color->components());
        $this->colour->assertOutputIntent();
        $this->colour->assertDeviceColour(self::deviceSpace(match (true) {
            $color instanceof Spot => $color->alternate,
            $color instanceof DeviceN => $color->alternate(),
            default => $color,
        }));

        return match (true) {
            $color instanceof Gray => "{$components} {$ops[0]}",
            $color instanceof Rgb => "{$components} {$ops[1]}",
            $color instanceof Cmyk => "{$components} {$ops[2]}",
            $color instanceof Lab => $this->colorSpaceOps($this->labColorSpace(), $components, $ops),
            $color instanceof Spot => $this->colorSpaceOps($this->spotColorSpace($color), $components, $ops),
            $color instanceof DeviceN => $this->colorSpaceOps($this->deviceNColorSpace($color), $components, $ops),
            $color instanceof IccColor => $this->colorSpaceOps($this->iccColorSpace($color), $components, $ops),
            default => throw new InvalidArgumentException(sprintf(
                'Colours must be Gray, Rgb, Cmyk, Lab, Spot, DeviceN or IccColor; %s is not one of them.',
                $color::class,
            )),
        };
    }

    public function shading(AbstractGradient $gradient): Reference
    {
        $this->colour->assertOutputIntent();
        $this->colour->assertDeviceColour($gradient->colorSpaceName()->value, 'gradients');
        return $this->objects->shared("sh:{$gradient->key()}", $gradient->toShading(...));
    }

    /**
     * A coloured tiling pattern whose cell draws the template, stepped $xStep and $yStep apart in
     * pattern space; the matrix maps pattern space to the default space of the page or form.
     */
    public function tilingPattern(Template $tile, float $xStep, float $yStep, Matrix $matrix): Reference
    {
        if (0.0 === $xStep || 0.0 === $yStep) {
            throw new InvalidArgumentException(sprintf(
                'A tiling pattern needs non-zero steps, not %s by %s.',
                Num::format($xStep),
                Num::format($yStep),
            ));
        }
        $this->objects->assertOwns($tile->objects(), 'A template');
        $ref = $tile->reference();

        return $this->objects->shared(
            "tile:{$ref->id}:" . Num::format($xStep) . ':' . Num::format($yStep) . ":{$matrix->toPdf()}",
            static fn(): Stream => new Stream(Dictionary::of([
                'PatternType' => 1,
                'PaintType'   => 1,
                'TilingType'  => 1,
                'BBox'        => $tile->boundingBox(),
                'XStep'       => $xStep,
                'YStep'       => $yStep,
                'Resources'   => Dictionary::of(['XObject' => Dictionary::of(['T0' => $ref])]),
                'Matrix'      => $matrix->toArray(),
            ]), "/T0 Do\n"),
        );
    }

    /** @param array{string, string, string, string, string} $ops */
    private function colorSpaceOps(Reference $colorSpace, string $components, array $ops): string
    {
        $name = $this->resources->colorSpace($colorSpace);

        return "/{$name} {$ops[3]} {$components} {$ops[4]}";
    }

    /**
     * A DeviceN colour space: the colorants' names, their shared alternate space, and a tint
     * transform that mixes them there as overprinted inks (each darkens what is under it by its
     * tint). Spot colorants are listed with their Separation spaces, so they match the same inks
     * used alone.
     */
    private function deviceNColorSpace(DeviceN $color): Reference
    {
        $alternate = $color->alternate();
        $key       = 'devn';
        foreach ($color->colorants as $colorant) {
            $key .= ':' . $colorant->name . '=' . Num::list($colorant->alternate->components());
        }
        $spots = [];
        foreach ($color->colorants as $colorant) {
            if (in_array($colorant->name, DeviceN::PROCESS, strict: true)) {
                continue;
            }
            $spots[$colorant->name] = $this->spotColorSpace($colorant);
        }

        return $this->objects->shared($key, function () use ($color, $alternate, $spots): array {
            $space = [
                new Name('DeviceN'),
                array_map(static fn(Spot $colorant): Name => new Name($colorant->name), $color->colorants),
                new Name(self::deviceSpace($alternate)),
                $this->objects->add(self::mixFunction($color)),
            ];
            if ([] !== $spots) {
                $space[] = Dictionary::of(['Colorants' => Dictionary::of($spots)]);
            }

            return $space;
        });
    }

    private function iccColorSpace(IccColor $color): Reference
    {
        $profile = $color->profile;
        $stream  = $this->objects->shared(
            "iccstream:{$profile->key()}",
            static fn(): Stream => new Stream(Dictionary::of(['N' => $profile->componentCount]), $profile->data),
        );

        return $this->objects->shared(
            "icc:{$profile->key()}",
            /** @return array{Name, Reference} */
            static fn(): array => [new Name('ICCBased'), $stream],
        );
    }

    /** CIE L*a*b* under D50, the white point of colour books and ICC profiles. */
    private function labColorSpace(): Reference
    {
        return $this->objects->shared(
            'lab:d50',
            /** @return array{Name, Dictionary} */
            static fn(): array => [
                new Name('Lab'),
                Dictionary::of(['WhitePoint' => [0.9642, 1, 0.8249], 'Range' => [-128, 127, -128, 127]]),
            ],
        );
    }

    private function spotColorSpace(Spot $spot): Reference
    {
        $alternate = $spot->alternate;
        $key       = 'sep:' . $spot->name . ':' . $alternate::class . ':' . Num::list($alternate->components());

        $lab = $alternate instanceof Lab ? $this->labColorSpace() : null;

        return $this->objects->shared($key, static function () use ($spot, $alternate, $lab): array {
            $white = match (true) {
                $alternate instanceof Gray => [1.0],
                $alternate instanceof Rgb => [1.0, 1.0, 1.0],
                $alternate instanceof Lab => [100.0, 0.0, 0.0],
                default => [0.0, 0.0, 0.0, 0.0],
            };

            return [
                new Name('Separation'),
                new Name($spot->name),
                $alternate instanceof Lab ? $lab : new Name(self::deviceSpace($alternate)),
                Dictionary::of([
                    'FunctionType' => 2,
                    'Domain'       => [0, 1],
                    'C0'           => $white,
                    'C1'           => $alternate->components(),
                    'N'            => 1,
                ]),
            ];
        });
    }
}
