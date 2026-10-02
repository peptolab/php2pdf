<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use function max;
use function ord;
use function sprintf;
use function str_contains;
use function str_starts_with;
use function substr;

/**
 * What a converted image could lose: what is known of its source's colour model, ICC profile,
 * bit depth and alpha channel, from the file's own header or from ext-imagick.
 *
 * @internal
 */
final readonly class ImageTraits
{
    /**
     * @param string|null $colourModel a colour model PNG cannot hold (CMYK, CIELab...), or null
     *                                 for grey, RGB and palettes
     * @param bool        $iccProfile  whether the source carries an ICC profile
     * @param int         $bits        the deepest sample, 0 when unknown
     * @param bool        $alpha       whether the source has an alpha channel or transparency
     */
    public function __construct(
        public ?string $colourModel = null,
        public bool $iccProfile = false,
        public int $bits = 0,
        public bool $alpha = false,
    ) {}

    /**
     * What a sniff of the header tells: the depth of an AVIF file, and whether a WebP, AVIF or
     * HEIF file embeds an ICC profile.
     *
     * @param array{0: int, 1: int, 2: int, 3: string, bits: int, channels: int, mime: string}|false $size
     *        what getimagesize() reports, if anything
     */
    public static function sniff(string $data, array|false $size): self
    {
        $webpIcc =
            str_starts_with($data, 'RIFF')
            && 'WEBPVP8X' === substr($data, offset: 8, length: 8)
            && 0 !== (ord($data[20] ?? "\0") & 0x20);
        $header  = substr($data, offset: 0, length: 65_536);
        $heifIcc = str_contains($header, 'colrprof') || str_contains($header, 'colrrICC');
        $bits    = false !== $size && 'image/avif' === $size['mime'] ? $size['bits'] : 0;

        return new self(
            iccProfile: $webpIcc || $heifIcc,
            bits: $bits,
        );
    }

    /**
     * What converting to $result would lose: one reason per loss, none when the result keeps
     * everything known of this source. An ICC profile counts as lost unless $result has one.
     *
     * @return list<string>
     */
    public function lostIn(self $result): array
    {
        $losses = [];
        if (null !== $this->colourModel && $result->colourModel !== $this->colourModel) {
            $losses[] = sprintf('it is %s and would become RGB', $this->colourModel);
        }
        if ($this->iccProfile && ! $result->iccProfile) {
            $losses[] = 'its ICC profile would not be kept';
        }
        if ($this->bits > $result->bits) {
            $losses[] = sprintf('its %d-bit samples would be cut to %d bits', $this->bits, $result->bits);
        }
        if ($this->alpha && ! $result->alpha) {
            $losses[] = 'its alpha channel would be lost';
        }

        return $losses;
    }

    /** Everything either source knows. */
    public function merge(self $other): self
    {
        return new self(
            $this->colourModel ?? $other->colourModel,
            $this->iccProfile || $other->iccProfile,
            max($this->bits, $other->bits),
            $this->alpha || $other->alpha,
        );
    }
}
