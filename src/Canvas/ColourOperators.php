<?php

declare(strict_types=1);

namespace PhpPdf\Canvas;

use PhpPdf\Color\AbstractGradient;
use PhpPdf\Color\Cmyk;
use PhpPdf\Color\ColorInterface;
use PhpPdf\Color\Gray;
use PhpPdf\Color\IccColor;
use PhpPdf\Color\Rgb;
use PhpPdf\Color\Spot;
use PhpPdf\Core\Num;
use PhpPdf\Core\Object\Dictionary;
use PhpPdf\Core\Object\Name;
use PhpPdf\Core\Object\Reference;
use PhpPdf\Core\Object\Stream;
use PhpPdf\Document\Document;
use PhpPdf\Document\Resources;
use PhpPdf\Exception\InvalidArgumentException;

use function sprintf;

/**
 * The operators that select a colour, and the colour spaces and shadings they need, written once
 * per document and named in the canvas's resources. PDF/X rules are checked as colours are used.
 *
 * @internal
 */
final readonly class ColourOperators
{
    public function __construct(
        private Document $document,
        private Resources $resources,
    ) {}

    /** The device colour space a colour is drawn in, or ICCBased when it carries its own profile. */
    private static function deviceSpace(ColorInterface $color): string
    {
        return match (true) {
            $color instanceof Gray => 'DeviceGray',
            $color instanceof Rgb => 'DeviceRGB',
            $color instanceof Cmyk => 'DeviceCMYK',
            default => 'ICCBased',
        };
    }

    /** @param array{string, string, string, string, string} $ops gray, rgb, cmyk, colour-space and component operators */
    public function ops(ColorInterface $color, array $ops): string
    {
        $components = Num::list($color->components());
        $this->document->pdfx()->assertOutputIntent();
        $this->document
            ->pdfx()
            ->assertDeviceColour(self::deviceSpace($color instanceof Spot ? $color->alternate : $color));

        return match (true) {
            $color instanceof Gray => "{$components} {$ops[0]}",
            $color instanceof Rgb => "{$components} {$ops[1]}",
            $color instanceof Cmyk => "{$components} {$ops[2]}",
            $color instanceof Spot => $this->colorSpaceOps($this->spotColorSpace($color), $components, $ops),
            $color instanceof IccColor => $this->colorSpaceOps($this->iccColorSpace($color), $components, $ops),
            default => throw new InvalidArgumentException(sprintf(
                'Colours must be Gray, Rgb, Cmyk, Spot or IccColor; %s is not one of them.',
                $color::class,
            )),
        };
    }

    public function shading(AbstractGradient $gradient): Reference
    {
        $this->document->pdfx()->assertOutputIntent();
        $this->document->pdfx()->assertDeviceColour($gradient->colorSpaceName()->value, 'gradients');
        return $this->document->objects()->shared("sh:{$gradient->key()}", $gradient->toShading(...));
    }

    /** @param array{string, string, string, string, string} $ops */
    private function colorSpaceOps(Reference $colorSpace, string $components, array $ops): string
    {
        $name = $this->resources->colorSpace($colorSpace);

        return "/{$name} {$ops[3]} {$components} {$ops[4]}";
    }

    private function iccColorSpace(IccColor $color): Reference
    {
        $profile = $color->profile;
        $stream  = $this->document->objects()->shared(
            "iccstream:{$profile->key()}",
            static fn(): Stream => new Stream(Dictionary::of(['N' => $profile->componentCount]), $profile->data),
        );

        return $this->document->objects()->shared(
            "icc:{$profile->key()}",
            /** @return array{Name, Reference} */
            static fn(): array => [new Name('ICCBased'), $stream],
        );
    }

    private function spotColorSpace(Spot $spot): Reference
    {
        $alternate = $spot->alternate;
        $key       = 'sep:' . $spot->name . ':' . $alternate::class . ':' . Num::list($alternate->components());

        return $this->document->objects()->shared($key, static function () use ($spot, $alternate): array {
            $white = match (true) {
                $alternate instanceof Gray => [1.0],
                $alternate instanceof Rgb => [1.0, 1.0, 1.0],
                default => [0.0, 0.0, 0.0, 0.0],
            };
            $space = match (true) {
                $alternate instanceof Gray => 'DeviceGray',
                $alternate instanceof Rgb => 'DeviceRGB',
                default => 'DeviceCMYK',
            };

            return [
                new Name('Separation'),
                new Name($spot->name),
                new Name($space),
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
