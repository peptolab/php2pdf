<?php

declare(strict_types=1);

namespace Php2Pdf\Color;

use Override;
use Php2Pdf\Exception\InvalidArgumentException;

use function array_map;
use function array_values;
use function count;
use function in_array;
use function sprintf;

/**
 * Several inks mixed in one colour (a DeviceN colour space): a duotone, or a spot colour over
 * process inks. Each colorant is a Spot whose tint is its share; process inks are named Cyan,
 * Magenta, Yellow and Black. Every colorant's alternate is in the same device space, and where
 * the inks are not printed they mix in it as overprinted inks do: each one darkens what is under
 * it by its tint.
 *
 * @api
 */
final readonly class DeviceN implements ColorInterface
{
    /** The process ink names DeviceN gives meaning to. */
    public const array PROCESS = ['Cyan', 'Magenta', 'Yellow', 'Black'];

    /** The device space the inks mix in where they are not printed. */
    private Gray|Rgb|Cmyk $space;

    /** @param list<Spot> $colorants */
    public function __construct(
        public array $colorants,
    ) {
        if ([] === $colorants || count($colorants) > 32) {
            throw new InvalidArgumentException(sprintf(
                'A DeviceN colour needs 1 to 32 colorants, not %d.',
                count($colorants),
            ));
        }
        $names = [];
        $space = null;
        foreach ($colorants as $colorant) {
            $alternate = $colorant->alternate;
            if ($alternate instanceof Lab) {
                throw new InvalidArgumentException(sprintf(
                    'Colorant "%s" has a Lab alternate; DeviceN mixes inks in Gray, Rgb or Cmyk.',
                    $colorant->name,
                ));
            }
            if (in_array($colorant->name, $names, strict: true)) {
                throw new InvalidArgumentException(sprintf('Colorant "%s" appears twice.', $colorant->name));
            }
            $space ??= $alternate;
            if ($alternate::class !== $space::class) {
                throw new InvalidArgumentException(
                    'The colorants of a DeviceN colour must all have alternates in one colour space (Gray, Rgb or Cmyk).',
                );
            }
            $names[] = $colorant->name;
        }
        $this->space = $space;
    }

    /** The process inks as colorants, each with its CMYK alternate: black, cyan, magenta, yellow. */
    public static function black(float $tint = 1.0): Spot
    {
        return new Spot('Black', new Cmyk(0.0, 0.0, 0.0, 1.0), $tint);
    }

    public static function cyan(float $tint = 1.0): Spot
    {
        return new Spot('Cyan', new Cmyk(1.0, 0.0, 0.0, 0.0), $tint);
    }

    public static function magenta(float $tint = 1.0): Spot
    {
        return new Spot('Magenta', new Cmyk(0.0, 1.0, 0.0, 0.0), $tint);
    }

    public static function yellow(float $tint = 1.0): Spot
    {
        return new Spot('Yellow', new Cmyk(0.0, 0.0, 1.0, 0.0), $tint);
    }

    /** A colour in the device space the colorants' alternates share (the first one's). */
    public function alternate(): Gray|Rgb|Cmyk
    {
        return $this->space;
    }

    #[Override]
    public function componentCount(): int
    {
        return count($this->colorants);
    }

    #[Override]
    public function components(): array
    {
        return array_map(static fn(Spot $colorant): float => $colorant->tint, $this->colorants);
    }

    /** The same inks at other tints, one per colorant in order. */
    public function withTints(float ...$tints): self
    {
        if (count($tints) !== count($this->colorants)) {
            throw new InvalidArgumentException(sprintf(
                'Give one tint per colorant: %d colorants, %d tints.',
                count($this->colorants),
                count($tints),
            ));
        }
        return new self(array_map(
            static fn(Spot $colorant, float $tint): Spot => $colorant->withTint($tint),
            $this->colorants,
            array_values($tints),
        ));
    }
}
