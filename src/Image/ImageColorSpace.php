<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Php2Pdf\Core\Object\HexString;
use Php2Pdf\Core\Object\Name;
use Php2Pdf\Core\Object\Reference;

use function intdiv;
use function strlen;

/**
 * The colour space of decoded image samples: a device space, or an indexed palette over one.
 *
 * @api
 */
final readonly class ImageColorSpace
{
    private function __construct(
        public string $device,
        public int $components,
        public ?string $palette,
    ) {}

    public static function cmyk(): self
    {
        return new self('DeviceCMYK', 4, null);
    }

    public static function gray(): self
    {
        return new self('DeviceGray', 1, null);
    }

    /** @param string $palette RGB triplets, one per index */
    public static function indexed(string $palette): self
    {
        return new self('DeviceRGB', 1, $palette);
    }

    public static function rgb(): self
    {
        return new self('DeviceRGB', 3, null);
    }

    public function isIndexed(): bool
    {
        return null !== $this->palette;
    }

    /**
     * The /ColorSpace entry. An ICC-based space replaces the device space when a profile is given.
     *
     * @return Name|list<Name|HexString|Reference|int|list<Name|Reference>>
     */
    public function toPdf(?Reference $iccBased = null): Name|array
    {
        $base = null === $iccBased ? new Name($this->device) : [new Name('ICCBased'), $iccBased];
        if (null === $this->palette) {
            return $base;
        }
        $entries = intdiv(strlen($this->palette), num2: 3);

        return [new Name('Indexed'), $base, $entries - 1, new HexString($this->palette)];
    }
}
