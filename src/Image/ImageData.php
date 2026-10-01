<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Php2Pdf\Core\Object\Dictionary;
use Php2Pdf\Exception\InvalidArgumentException;

use function sprintf;

/**
 * A decoded (or passed-through) image ready to be written as an image XObject.
 *
 * @api
 */
final readonly class ImageData
{
    /**
     * @param string          $data         sample data, already encoded with $filter when one is set
     * @param string|null     $filter       DCTDecode, JPXDecode, FlateDecode, or null for raw samples
     * @param Dictionary|null $decodeParms  predictor parameters for FlateDecode
     * @param list<int>|null  $decode       a /Decode array, e.g. to invert Adobe CMYK JPEGs
     * @param string|null     $softMask     8-bit alpha samples, one byte per pixel, uncompressed
     * @param list<int>|null  $colorKeyMask /Mask ranges for colour-key transparency
     * @param string|null     $iccProfile   an embedded ICC profile matching the colour space
     * @param int             $orientation  the EXIF orientation (1 to 8) the image is shown in
     * @param bool            $smaskInData  whether JPEG 2000 data carries its own alpha channel
     * @param string|null     $photoshop    Photoshop image resource blocks, which may hold paths
     * @param ClippingPath|null $clip       the path the image is clipped to when drawn
     */
    public function __construct(
        public int $width,
        public int $height,
        public int $bitsPerComponent,
        public ImageColorSpace $colorSpace,
        public string $data,
        public ?string $filter = null,
        public ?Dictionary $decodeParms = null,
        public ?array $decode = null,
        public ?string $softMask = null,
        public ?array $colorKeyMask = null,
        public ?string $iccProfile = null,
        public float $dpiX = 72.0,
        public float $dpiY = 72.0,
        public int $orientation = 1,
        public bool $smaskInData = false,
        public ?string $photoshop = null,
        public ?ClippingPath $clip = null,
    ) {}

    public function hasTransparency(): bool
    {
        return null !== $this->softMask || null !== $this->colorKeyMask || $this->smaskInData;
    }

    /** Whether the orientation turns the image a quarter turn, swapping its width and height. */
    public function isTransposed(): bool
    {
        return $this->orientation >= 5;
    }

    /** The image as loaded with options: at another resolution, or ignoring its orientation. */
    public function withOptions(ImageOptions $options): self
    {
        if (
            null === $options->dpi
            && (
                $options->orientation
                || 1 === $this->orientation
            )
            && false === $options->clippingPath
        ) {
            return $this;
        }

        return new self(
            $this->width,
            $this->height,
            $this->bitsPerComponent,
            $this->colorSpace,
            $this->data,
            $this->filter,
            $this->decodeParms,
            $this->decode,
            $this->softMask,
            $this->colorKeyMask,
            $this->iccProfile,
            $options->dpi ?? $this->dpiX,
            $options->dpi ?? $this->dpiY,
            $options->orientation ? $this->orientation : 1,
            $this->smaskInData,
            $this->photoshop,
            $this->clippingPath($options->clippingPath),
        );
    }

    /**
     * The path to clip to: none, the one Photoshop marks as the clipping path, or one by name.
     *
     * @throws InvalidArgumentException when the image has no such path
     */
    private function clippingPath(bool|string $request): ?ClippingPath
    {
        if (false === $request) {
            return null;
        }
        $resources = new PhotoshopResources($this->photoshop ?? '');
        $path      = true === $request ? $resources->clippingPath() : $resources->path($request);

        return $path ?? throw new InvalidArgumentException(
            true === $request
                ? 'The image has no clipping path.'
                : sprintf('The image has no path named "%s".', $request),
        );
    }
}
