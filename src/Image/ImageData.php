<?php

declare(strict_types=1);

namespace Php2Pdf\Image;

use Php2Pdf\Exception\InvalidArgumentException;
use Php2Pdf\Geometry\Path;

use function sprintf;

/**
 * A decoded (or passed-through) image: its samples, its soft mask, and how it is shown (its
 * resolution, orientation, and the path it is clipped to).
 *
 * @api
 */
final readonly class ImageData
{
    /**
     * @param int         $orientation the EXIF orientation (1 to 8) the image is shown in
     * @param string|null $photoshop   Photoshop image resource blocks, which may hold paths
     * @param Path|null   $clip        the path the image is clipped to when drawn
     */
    public function __construct(
        public Raster $raster,
        public ?SoftMask $softMask = null,
        public float $dpiX = 72.0,
        public float $dpiY = 72.0,
        public int $orientation = 1,
        public ?string $photoshop = null,
        public ?Path $clip = null,
    ) {}

    public function hasTransparency(): bool
    {
        return null !== $this->softMask || null !== $this->raster->colorKeyMask || $this->raster->smaskInData;
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
            $this->raster,
            $this->softMask,
            $options->dpi ?? $this->dpiX,
            $options->dpi ?? $this->dpiY,
            $options->orientation ? $this->orientation : 1,
            $this->photoshop,
            $this->clippingPath($options->clippingPath),
        );
    }

    /**
     * The image without its samples, its soft mask's or its ICC profile's bytes: what placing an
     * image already written needs, so a document keeps no image's pixels once they are written.
     */
    public function withoutSamples(): self
    {
        $raster = $this->raster;

        return new self(
            new Raster(
                $raster->width,
                $raster->height,
                $raster->bitsPerComponent,
                $raster->colorSpace,
                '',
                $raster->filter,
                $raster->decodeParms,
                $raster->decode,
                $raster->colorKeyMask,
                null === $raster->iccProfile ? null : '',
                $raster->smaskInData,
            ),
            null === $this->softMask ? null : new SoftMask('', $this->softMask->bits),
            $this->dpiX,
            $this->dpiY,
            $this->orientation,
            $this->photoshop,
            $this->clip,
        );
    }

    /**
     * The path to clip to: none, the one Photoshop marks as the clipping path, or one by name.
     *
     * @throws InvalidArgumentException when the image has no such path
     */
    private function clippingPath(bool|string $request): ?Path
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
