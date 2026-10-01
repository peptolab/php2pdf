<?php

declare(strict_types=1);

namespace Php2Pdf\Color;

use Override;
use Php2Pdf\Exception\InvalidArgumentException;

use function ctype_xdigit;
use function hexdec;
use function ltrim;
use function sprintf;
use function strlen;
use function substr;

/**
 * DeviceRGB with components from 0.0 to 1.0.
 *
 * @api
 */
final readonly class Rgb implements ColorInterface
{
    public function __construct(
        public float $red,
        public float $green,
        public float $blue,
    ) {}

    /** From 0–255 integer components. */
    public static function from255(int $red, int $green, int $blue): self
    {
        return new self($red / 255.0, $green / 255.0, $blue / 255.0);
    }

    /** From a CSS-style hex string: #rgb, #rrggbb (with or without the hash). */
    public static function fromHex(string $hex): self
    {
        $hex = ltrim($hex, characters: '#');
        if (3 === strlen($hex)) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }
        if (6 !== strlen($hex) || ! ctype_xdigit($hex)) {
            throw new InvalidArgumentException(sprintf('Invalid hex colour "%s".', $hex));
        }

        return self::from255(
            (int) hexdec(substr($hex, offset: 0, length: 2)),
            (int) hexdec(substr($hex, offset: 2, length: 2)),
            (int) hexdec(substr($hex, offset: 4, length: 2)),
        );
    }

    #[Override]
    public function componentCount(): int
    {
        return 3;
    }

    #[Override]
    public function components(): array
    {
        return [$this->red, $this->green, $this->blue];
    }
}
